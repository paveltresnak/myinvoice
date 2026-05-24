<?php

declare(strict_types=1);

namespace MyInvoice\Service\Import;

use GuzzleHttp\Client;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Service\Auth\SecretEncryption;
use Psr\Log\LoggerInterface;

/**
 * Anthropic Claude API client pro AI extraction z PDF faktur.
 *
 * BYOK — per-tenant API klíč (uživatel platí sám). Default model:
 * claude-haiku-4-5 (~$0.001/faktura), pro lepší kvalitu lze přepnout
 * na Sonnet 4.6 (~$0.005/faktura).
 *
 * Cena za extrakci PDF s ~5 řádkami:
 *   Haiku 4.5:  ~3000 input tokens (PDF base64) + ~500 output tokens
 *               = $0.0006 input + $0.0025 output = ~$0.003
 *   Sonnet 4.6: ~$0.012 (4× dráž)
 *
 * Strict JSON output přes structured response — anti-hallucination.
 */
final class AnthropicClient
{
    private const API_URL = 'https://api.anthropic.com/v1/messages';
    private const API_VERSION = '2023-06-01';
    private const TIMEOUT = 120; // PDF extraction trvá 10-30s typicky
    private const MAX_PDF_BYTES = 32 * 1024 * 1024; // 32 MiB hard limit (Anthropic limit)
    private const MAX_RETRIES = 3;
    private const MAX_RETRY_SLEEP = 65; // seconds — pokrývá 1-minute token bucket reset
    // Throttle: pokud `remaining_input_tokens` z headerů klesne pod tuto hranici,
    // další volání počká do `reset` timestampu. Drží řadu PDF v batchi z toho,
    // aby cumulativně přefoukla 50k token/min limit a způsobila 429.
    private const RATE_LIMIT_THROTTLE_THRESHOLD = 5000;

    private Client $http;

    /** @var array{remaining_input:int, reset_at:int}|null */
    private ?array $rateLimitState = null;

    public function __construct(
        private readonly Connection $db,
        private readonly SecretEncryption $crypto,
        private readonly LoggerInterface $logger,
    ) {
        $this->http = new Client([
            'timeout' => self::TIMEOUT,
            'http_errors' => false,
        ]);
    }

    /**
     * @return array{api_key:string, default_model:string}|null
     */
    public function getCredentials(int $supplierId): ?array
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT anthropic_api_key_enc, anthropic_default_model FROM supplier WHERE id = ?'
        );
        $stmt->execute([$supplierId]);
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);
        if (!$row || empty($row['anthropic_api_key_enc'])) return null;
        try {
            $key = $this->crypto->decrypt((string) $row['anthropic_api_key_enc']);
        } catch (\Throwable $e) {
            $this->logger->error('Anthropic API key decryption failed', ['supplier_id' => $supplierId]);
            return null;
        }
        return [
            'api_key'       => $key,
            'default_model' => (string) ($row['anthropic_default_model'] ?? 'claude-haiku-4-5'),
        ];
    }

    public function setCredentials(int $supplierId, string $apiKey, ?string $defaultModel = null): void
    {
        $enc = $apiKey === '' ? null : $this->crypto->encrypt($apiKey);
        $model = $defaultModel ?: 'claude-haiku-4-5';
        $this->db->pdo()->prepare(
            'UPDATE supplier SET anthropic_api_key_enc = ?, anthropic_default_model = ?
              WHERE id = ?'
        )->execute([$enc, $model, $supplierId]);
    }

    public function updateDefaultModel(int $supplierId, string $defaultModel): void
    {
        $this->db->pdo()->prepare(
            'UPDATE supplier SET anthropic_default_model = ? WHERE id = ?'
        )->execute([$defaultModel, $supplierId]);
    }

    /**
     * Test connectivity — pošle minimalistický prompt, ověří 200 OK.
     */
    public function testConnection(int $supplierId): array
    {
        $creds = $this->getCredentials($supplierId);
        if ($creds === null) {
            return ['ok' => false, 'error' => 'API key nenastaven'];
        }
        try {
            ['code' => $code, 'body' => $body] = $this->postWithRetry([
                'model' => $creds['default_model'],
                'max_tokens' => 10,
                'messages' => [['role' => 'user', 'content' => 'Reply OK']],
            ], $creds['api_key']);
            if ($code !== 200) {
                $msg = is_array($body) ? ($body['error']['message'] ?? 'HTTP ' . $code) : 'HTTP ' . $code;
                return ['ok' => false, 'error' => $msg];
            }
            return ['ok' => true, 'model' => $body['model'] ?? null, 'usage' => $body['usage'] ?? null];
        } catch (\Throwable $e) {
            return ['ok' => false, 'error' => $e->getMessage()];
        }
    }

    /**
     * Extrahuje strukturovaná data z PDF faktury pomocí Claude vision.
     *
     * Workflow:
     *   1. PDF → base64
     *   2. Strict system prompt s JSON schema definicí
     *   3. POST /messages s document content block (type=document, source.type=base64, source.data=...)
     *   4. Parse response.content[0].text jako JSON
     *   5. Validate proti hallucinations (caller zodpovědný)
     *
     * @return array{ok:bool, data?:array<string,mixed>, error?:string, model?:string, usage?:array<string,int>}
     */
    public function extractInvoice(int $supplierId, string $pdfBytes, ?string $modelOverride = null): array
    {
        $creds = $this->getCredentials($supplierId);
        if ($creds === null) {
            return ['ok' => false, 'error' => 'Anthropic API key nenastaven pro tohoto suppliera.'];
        }
        if (strlen($pdfBytes) > self::MAX_PDF_BYTES) {
            return ['ok' => false, 'error' => 'PDF přesahuje limit ' . self::MAX_PDF_BYTES . ' B.'];
        }
        if (!str_starts_with($pdfBytes, '%PDF')) {
            return ['ok' => false, 'error' => 'Soubor není validní PDF (chybí %PDF header).'];
        }

        $model = $modelOverride ?: $creds['default_model'];
        $base64Pdf = base64_encode($pdfBytes);

        // Načti tenant info (název + IČ + DIČ) abychom AI mohli explicitně říct,
        // že tenant je odběratel (customer), NIKDY dodavatel. Bez tohoto AI občas
        // zamění vendor↔customer u faktur kde má dodavatel velkou vlastní hlavičku
        // (NC Auto / BMW Service / mobilní operátoři) a tenanta dá do vendor pozice.
        $tenantBlock = $this->buildTenantContextBlock($supplierId);

        $systemPrompt = $tenantBlock . <<<'EOT'
Jsi expert na extrakci dat z českých a slovenských faktur. Z PDF přílohy vytáhneš strukturovaná data ve striktním JSON formátu.

PRAVIDLA:
- Vrátíš JEN platný JSON (žádný markdown, žádný komentář před/po).
- Pokud pole neexistuje v PDF, použij null. NEVYMÝŠLEJ data.
- Datumy ve formátu ISO YYYY-MM-DD.
- Částky čísla bez měny (přidej zvlášť do `currency`).
- IČ/DIČ ořež na čísla (CZ12345678 → "12345678"), pokud má prefix země ponech v `dic` jak je.
- VAT rate jako desetinné číslo (21.0, 15.0, 12.0, 10.0, 0.0).

JSON schema:
{
  "vendor": {
    "company_name": string,
    "ic": string|null,
    "dic": string|null,
    "street": string|null,
    "city": string|null,
    "zip": string|null,
    "country_iso2": "CZ"|"SK"|...,
    "email": string|null,
    "phone": string|null,
    "web": string|null,
    "bank_account": string|null
  },
  "customer": {
    "company_name": string|null,
    "ic": string|null,
    "dic": string|null
  },
  "vendor_invoice_number": string,
  "varsymbol": string|null,
  "document_kind": "invoice"|"credit_note"|"advance"|"receipt",
  "issue_date": "YYYY-MM-DD",
  "tax_date": "YYYY-MM-DD"|null,
  "due_date": "YYYY-MM-DD"|null,
  "currency": "CZK"|"EUR"|"USD"|...,
  "items": [
    {
      "description": string,
      "quantity": number,
      "unit": string,
      "unit_price_without_vat": number,
      "vat_rate": number
    }
  ],
  "total_without_vat": number|null,
  "total_with_vat": number|null,
  "total_with_vat_rounded": number|null,
  "already_paid": boolean
}

DŮLEŽITÉ k poli `document_kind`:
- Pokud nadpis / hlavička PDF obsahuje "Opravný daňový doklad", "Dobropis",
  "Opravná faktura", "Credit note", "Storno faktura", "Storno doklad",
  nebo doklad jinak signalizuje vrácení / opravu předchozí faktury
  (např. záporné částky, odkaz na opravovanou fakturu) → vrať `"credit_note"`.
- Pokud doklad je "Zálohová faktura", "Proforma", "Proforma faktura",
  "Zálohový list", "Advance invoice" → vrať `"advance"`.
- Pokud doklad je "Účtenka", "Paragon", "Pokladní doklad", "Receipt" → vrať `"receipt"`.
- Jinak (běžná faktura / daňový doklad) → vrať `"invoice"`.

DŮLEŽITÉ k položkám u dobropisu (`document_kind = "credit_note"`):
- `quantity` a `unit_price_without_vat` vrať jako **kladná čísla** (jak jsou na PDF).
  Záporné znaménko si aplikuje importér automaticky podle `document_kind`.
- Stejně tak `total_without_vat`, `total_with_vat`, `total_with_vat_rounded`
  vrať jako **kladná čísla** (absolutní hodnoty z PDF).

DŮLEŽITÉ k řádkům se slevou / rabatem / discount (jen u `document_kind = "invoice"`):
- Pokud řádek běžné faktury reprezentuje slevu / rabat / bonus snižující fakturu
  (popis obsahuje "sleva", "rabat", "discount", "bonus", "%" sleva, "Roční sleva"
  apod.) A na PDF je jeho jednotková cena nebo celková částka uvedena se
  znaménkem **MÍNUS** (např. `-643,50`, `-7 722,00`) nebo v závorkách (např.
  `(643,50)`) → vrať `unit_price_without_vat` jako **ZÁPORNÉ** číslo
  (např. `-643.50`). Slevy MUSÍ mít záporné znaménko, jinak by se přičetly
  k faktuře místo aby ji snížily.
- POZOR: toto NEPLATÍ pro dobropisy (`credit_note`) — u nich vždy kladné absolutní
  hodnoty, sign aplikuje importér podle `document_kind`.

DŮLEŽITÉ k poli `already_paid`:
- Pokud PDF obsahuje text typu "NEPLAŤTE, JIŽ UHRAZENO", "ZAPLACENO",
  "UHRAZENO", "PAID", "ALREADY PAID", "PAYMENT RECEIVED", "Hradí se ze zálohy"
  nebo podobné indikátory že faktura už byla zaplacena → vrať `true`.
- Pokud žádný takový text není (default scénář) → vrať `false`.

DŮLEŽITÉ k zaokrouhlení:
- `total_with_vat` = přesný součet (např. 228.69)
- `total_with_vat_rounded` = zaokrouhlená částka pokud je na PDF uvedeno
  zaokrouhlení (např. "229.00 Kč", "K úhradě: 229").
- Rozdíl (229 - 228.69 = 0.31) půjde do pole `rounding` faktury.
- Pokud na PDF NENÍ explicitní zaokrouhlení, vrať `total_with_vat_rounded: null`.

DŮLEŽITÉ k řádkům faktury (`items`):
- Vrať POUZE listové (atomické) položky — konkrétní práce, materiál, zboží.
  NIKDY agregační / subtotalové / součtové řádky.
- IGNORUJ jakýkoli řádek, který začíná nebo obsahuje (case-insensitive):
  "Celkem ", "Mezisoučet", "Subtotal", "Σ ", "Součet ", "Total " (pokud
  je to subtotal sekce, ne celková K úhradě), "Cena celkem za skupinu",
  "Cena celkem za sekci".
- U faktur s vícestupňovou strukturou (typicky autoservis — např. NC Auto
  s.r.o. / BMW Service: skupina práce → jednotlivé úkony → "Celkem Práce" →
  "Celkem <název skupiny>") vrať POUZE jednotlivé úkony s reálnými qty
  a unit_price. NIKDY součtové meziřádky — ty by ti při sečtení nafoukly
  celkovou částku 2-5× nad reálný total.
- Pokud na faktuře vidíš stejnou položku "Vyvážení kola" s qty 1 i jako
  součtový řádek "Celkem Vyvážení" s vypočtenou sumou — vrať POUZE ten s qty 1.

DŮLEŽITÉ k poli `total_with_vat`:
- Hodnota MUSÍ pocházet výhradně z hlavního finálního "K úhradě" /
  "Celkem k úhradě" / "CZK k zaplacení" / "Total amount due" / "K platbě"
  — typicky úplně dole na faktuře, často zvýrazněně (tučně/větším fontem).
- NIKDY neber `total_with_vat` ze subtotalu jednotlivé sekce/skupiny prací,
  ani ze součtu mezi-skupin ("Celkem Práce", "Celkem Materiál").
- Pokud máš pochybnost mezi více čísly, vyber NEJMENŠÍ logické. Subtotaly
  jsou typicky větší než K úhradě jen kvůli zaokrouhlení; součet sekcí >
  K úhradě téměř vždy znamená, že čteš špatný řádek.
- POKUD si nejsi jistý finálním totalem (nevidíš jasné "K úhradě"), vrať
  NULL místo hádání.

Příklad — faktura NC Auto s.r.o. (BMW Service), struktura:
  Sekce A: Práce
    Diagnostika              1 ks  500.00 Kč  →  ITEM
    Výměna oleje             1 ks  800.00 Kč  →  ITEM
    Celkem Práce                 1 300.00 Kč  →  IGNORE (subtotal)
  Sekce B: Materiál
    Olej 5W30                4 l   180.00 Kč  →  ITEM
    Filtr olejový            1 ks  280.00 Kč  →  ITEM
    Celkem Materiál              1 000.00 Kč  →  IGNORE (subtotal)
  Celkem bez DPH             2 300.00 Kč      →  IGNORE (grand subtotal)
  DPH 21 %                     483.00 Kč
  K úhradě                   2 783.00 Kč      →  total_with_vat = 2783.00
Výsledek: items = 4 řádky (NE 6 a NE 7); total_with_vat = 2783.00.
EOT;

        try {
            ['code' => $code, 'body' => $body] = $this->postWithRetry([
                'model' => $model,
                'max_tokens' => 4096,
                'system' => $systemPrompt,
                'messages' => [[
                    'role' => 'user',
                    'content' => [
                        [
                            'type' => 'document',
                            'source' => [
                                'type' => 'base64',
                                'media_type' => 'application/pdf',
                                'data' => $base64Pdf,
                            ],
                        ],
                        [
                            'type' => 'text',
                            'text' => 'Vytáhni strukturovaná data z této faktury podle JSON schema. Odpověz JEN samotným JSON, bez markdown.',
                        ],
                    ],
                ]],
            ], $creds['api_key']);
            if ($code !== 200) {
                $msg = is_array($body) ? ($body['error']['message'] ?? 'HTTP ' . $code) : 'HTTP ' . $code;
                return ['ok' => false, 'error' => $msg];
            }

            // Parse Claude's text response
            $text = (string) ($body['content'][0]['text'] ?? '');
            if ($text === '') {
                return ['ok' => false, 'error' => 'Prázdná odpověď od Claude'];
            }
            // Strip případné markdown code fences
            $text = preg_replace('/^```(?:json)?\s*|\s*```\s*$/m', '', $text);
            $data = json_decode((string) $text, true);
            if (!is_array($data)) {
                return ['ok' => false, 'error' => 'Claude vrátil invalid JSON: ' . substr($text, 0, 200)];
            }

            // Increment usage counter
            $this->db->pdo()->prepare(
                'UPDATE supplier SET anthropic_extractions_count = anthropic_extractions_count + 1 WHERE id = ?'
            )->execute([$supplierId]);

            return [
                'ok'    => true,
                'data'  => $data,
                'model' => $body['model'] ?? $model,
                'usage' => $body['usage'] ?? null,
            ];
        } catch (\Throwable $e) {
            $this->logger->error('Anthropic extractInvoice failed', ['supplier_id' => $supplierId, 'error' => $e->getMessage()]);
            return ['ok' => false, 'error' => $e->getMessage()];
        }
    }

    /**
     * Lightweight extrakce JEN total_with_vat z PDF — pro recheck / sanity check
     * scenarios kde nepotřebujeme items/klient/datumy. Vrátí jednu number, nebo
     * null pokud AI fail / nemůže najít K úhradě.
     *
     * Výhody vs extractInvoice():
     *   - max_tokens 100 místo 4096 (output ~10× kratší)
     *   - jednodušší prompt (kratší input tokens)
     *   - bez tenant context bloku (pro pure total extraction nepotřebné)
     *   - typicky 5-10× levnější per call (Haiku ~$0.0001 místo $0.001)
     *
     * Použití: jako AI fallback v `PdfTotalExtractor` když ISDOC + regex selžou.
     *
     * @return array{ok: bool, total?: ?float, error?: string, model?: string, usage?: array}
     */
    public function extractPdfTotal(int $supplierId, string $pdfBytes, ?string $modelOverride = null): array
    {
        $creds = $this->getCredentials($supplierId);
        if ($creds === null) {
            return ['ok' => false, 'error' => 'Anthropic API key nenastaven pro tohoto suppliera.'];
        }
        if (strlen($pdfBytes) > self::MAX_PDF_BYTES) {
            return ['ok' => false, 'error' => 'PDF přesahuje limit ' . self::MAX_PDF_BYTES . ' B.'];
        }
        if (!str_starts_with($pdfBytes, '%PDF')) {
            return ['ok' => false, 'error' => 'Soubor není validní PDF.'];
        }

        $model = $modelOverride ?: $creds['default_model'];
        $base64Pdf = base64_encode($pdfBytes);

        // Minimalistický prompt — chceme jediné číslo, JSON s jedním polem.
        $systemPrompt = <<<'EOT'
Z PDF faktury vrátíš JEN finální částku k úhradě (= "K úhradě", "Celkem k platbě", "Total to pay")
ve formátu JSON. Žádný markdown, žádné komentáře.

Schema: {"total_with_vat": number}
- number je číslo bez měny (z 1 502,00 Kč vrať 1502.00)
- Pokud finální K úhradě nelze určit jednoznačně, vrať {"total_with_vat": null}
- U DOBROPISU vrať kladné číslo (znaménko si aplikujeme my)
- POZOR na sekce "Z minulého období" / "Nedoplatek z minulého období" / "Přijaté platby" —
  to NENÍ aktuální K úhradě. Hledej PROVĚŘOVANÉ K úhradě v hlavním souhrnu.
EOT;

        try {
            ['code' => $code, 'body' => $body] = $this->postWithRetry([
                'model'      => $model,
                'max_tokens' => 100,
                'system'     => $systemPrompt,
                'messages'   => [[
                    'role'    => 'user',
                    'content' => [
                        [
                            'type'   => 'document',
                            'source' => [
                                'type'       => 'base64',
                                'media_type' => 'application/pdf',
                                'data'       => $base64Pdf,
                            ],
                        ],
                        [
                            'type' => 'text',
                            'text' => 'Vrať K úhradě podle JSON schema.',
                        ],
                    ],
                ]],
            ], $creds['api_key']);
            if ($code !== 200) {
                $msg = is_array($body) ? ($body['error']['message'] ?? 'HTTP ' . $code) : 'HTTP ' . $code;
                return ['ok' => false, 'error' => $msg];
            }

            $text = (string) ($body['content'][0]['text'] ?? '');
            $text = preg_replace('/^```(?:json)?\s*|\s*```\s*$/m', '', $text) ?? $text;
            $data = json_decode(trim($text), true);
            if (!is_array($data) || !array_key_exists('total_with_vat', $data)) {
                return ['ok' => false, 'error' => 'Claude vrátil invalid JSON: ' . substr($text, 0, 100)];
            }

            $total = $data['total_with_vat'];
            $total = is_numeric($total) ? (float) $total : null;

            // Increment usage counter (stejně jako extractInvoice, je to AI call)
            $this->db->pdo()->prepare(
                'UPDATE supplier SET anthropic_extractions_count = anthropic_extractions_count + 1 WHERE id = ?'
            )->execute([$supplierId]);

            return [
                'ok'    => true,
                'total' => $total,
                'model' => $body['model'] ?? $model,
                'usage' => $body['usage'] ?? null,
            ];
        } catch (\Throwable $e) {
            $this->logger->error('Anthropic extractPdfTotal failed', ['supplier_id' => $supplierId, 'error' => $e->getMessage()]);
            return ['ok' => false, 'error' => $e->getMessage()];
        }
    }

    /**
     * Sestaví prioritní hlavičku promptu s tenant info, aby AI vědělo, že
     * tato konkrétní firma je VŽDY odběratel (customer) — NIKDY dodavatel.
     *
     * Pomáhá u faktur kde dodavatel má dominantní hlavičku (autoservisy s logy,
     * mobilní operátoři s brandingem) a AI by jinak zaměnila vendor↔customer.
     *
     * Pokud tenant info nelze načíst (DB error / chybějící data), vrátí prázdný
     * string a prompt zůstane v původní podobě — žádný hard fail.
     */
    private function buildTenantContextBlock(int $supplierId): string
    {
        try {
            $stmt = $this->db->pdo()->prepare(
                'SELECT company_name, ic, dic FROM supplier WHERE id = ?'
            );
            $stmt->execute([$supplierId]);
            $t = $stmt->fetch(\PDO::FETCH_ASSOC);
        } catch (\Throwable) {
            return '';
        }
        if ($t === false || empty($t['company_name']) && empty($t['ic'])) {
            return '';
        }
        $name = (string) ($t['company_name'] ?? '');
        $ic   = (string) ($t['ic'] ?? '');
        $dic  = (string) ($t['dic'] ?? '');
        $hint = [];
        if ($name !== '') $hint[] = "název \"{$name}\"";
        if ($ic !== '')   $hint[] = "IČO \"{$ic}\"";
        if ($dic !== '')  $hint[] = "DIČ \"{$dic}\"";
        $tenantHint = implode(', ', $hint);

        // Heredoc bez interpolace by tu nešel (potřebuji vložit $tenantHint).
        // Používám sprintf místo, aby šlo escape jednoduše.
        return sprintf(
            "DŮLEŽITÝ KONTEXT (čti jako první, předchází všechna ostatní pravidla):\n"
            . "- Toto je extrakce PŘIJATÉ faktury pro firmu: %s.\n"
            . "- Tato firma je VŽDY odběratel (customer) — NIKDY ne dodavatel (vendor).\n"
            . "- Pokud v PDF vidíš tuto firmu (matchuj IČO nebo název), vrať ji v poli `customer`, NIKDY v poli `vendor`.\n"
            . "- Dodavatel (vendor) je VŽDY ta druhá strana — ten, kdo fakturu vystavil.\n"
            . "- POZOR: na fakturách autoservisů, mobilních operátorů, hostingových firem apod. má dodavatel typicky velkou\n"
            . "  hlavičku s logem nahoře, zatímco odběratel je v adresním bloku níže. NEpodléhej tomu — odběratele\n"
            . "  pozná podle shody s firmou z tohoto kontextu, dodavatel je vždy ta DRUHÁ strana.\n"
            . "- Pokud bys vrátil tuto firmu jako vendor, znamená to že jsi špatně přečetl PDF — importér to detekuje a fakturu zamítne.\n\n",
            $tenantHint,
        );
    }

    private function authHeaders(string $apiKey): array
    {
        return [
            'x-api-key'         => $apiKey,
            'anthropic-version' => self::API_VERSION,
            'content-type'      => 'application/json',
        ];
    }

    /**
     * POST /v1/messages s rate-limit obranou:
     *   1. **Throttle** — pokud poslední response ohlásil zbytek vstupních tokenů
     *      pod prahem, spí do `reset_at` (proaktivní vyhnutí se 429 v batchi).
     *   2. **Retry** — na HTTP 429 počká podle `retry-after` headeru a opakuje
     *      max `MAX_RETRIES`-krát s exponenciálním fallbackem.
     *
     * @param array<string,mixed> $payload
     * @return array{code:int, body:array<string,mixed>|null}
     */
    private function postWithRetry(array $payload, string $apiKey): array
    {
        $this->applyThrottle();
        $attempt = 0;
        while (true) {
            $resp = $this->http->post(self::API_URL, [
                'headers' => $this->authHeaders($apiKey),
                'json'    => $payload,
            ]);
            $code = $resp->getStatusCode();
            $body = json_decode((string) $resp->getBody(), true);
            $this->captureRateLimit($resp);

            if ($code !== 429 || $attempt >= self::MAX_RETRIES) {
                return ['code' => $code, 'body' => is_array($body) ? $body : null];
            }

            $sleep = $this->computeRetrySleep($resp, $attempt);
            $this->logger->info('Anthropic rate-limited, retrying', [
                'attempt' => $attempt + 1,
                'sleep'   => $sleep,
            ]);
            sleep($sleep);
            $attempt++;
        }
    }

    /**
     * Spí do `reset_at` pokud poslední response signalizoval, že zbývá málo
     * input tokenů. Cap na MAX_RETRY_SLEEP, aby se neudusila celá request.
     */
    private function applyThrottle(): void
    {
        if ($this->rateLimitState === null) return;
        if ($this->rateLimitState['remaining_input'] >= self::RATE_LIMIT_THROTTLE_THRESHOLD) return;
        $wait = $this->rateLimitState['reset_at'] - time();
        if ($wait <= 0) return;
        $wait = min(self::MAX_RETRY_SLEEP, $wait + 1);
        $this->logger->info('Anthropic throttle wait', [
            'seconds'         => $wait,
            'remaining_input' => $this->rateLimitState['remaining_input'],
        ]);
        sleep($wait);
        // Po čekání resetuj — header v další response nám stejně dá fresh hodnoty.
        $this->rateLimitState = null;
    }

    private function captureRateLimit(\Psr\Http\Message\ResponseInterface $resp): void
    {
        $remainingHdr = $resp->getHeaderLine('anthropic-ratelimit-input-tokens-remaining');
        $resetHdr     = $resp->getHeaderLine('anthropic-ratelimit-input-tokens-reset');
        if ($remainingHdr === '' || $resetHdr === '') return;
        try {
            $resetTs = (new \DateTimeImmutable($resetHdr))->getTimestamp();
        } catch (\Throwable) {
            return;
        }
        $this->rateLimitState = [
            'remaining_input' => (int) $remainingHdr,
            'reset_at'        => $resetTs,
        ];
    }

    private function computeRetrySleep(\Psr\Http\Message\ResponseInterface $resp, int $attempt): int
    {
        $retryAfter = (int) $resp->getHeaderLine('retry-after');
        if ($retryAfter <= 0) {
            // Fallback: 2, 4, 8 … s, capped.
            $retryAfter = (int) min(self::MAX_RETRY_SLEEP, 2 ** ($attempt + 1));
        }
        return (int) min(self::MAX_RETRY_SLEEP, max(1, $retryAfter));
    }
}
