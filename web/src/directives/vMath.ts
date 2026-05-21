import type { Directive } from 'vue'

/**
 * Safe math expression evaluator.
 *
 * Povolené znaky: digits, `+`, `-`, `*`, `/`, `(`, `)`, `.`, `,` (CZ desetinná čárka).
 * Žádné JS literály, funkce, ani víceslovné identifiers — protect před injection.
 *
 * Examples:
 *   "400-100"   → 300
 *   "400+100"   → 500
 *   "400/2"     → 200
 *   "12*1.21"   → 14.52
 *   "(100+50)*2" → 300
 *   "1234,56"   → 1234.56
 *   "abc"       → null (caller zachová původní string)
 */
export function evalMath(input: string): number | null {
  if (input === '' || input === null || input === undefined) return null
  let s = String(input).replace(/\s/g, '').replace(/ /g, '').replace(',', '.')
  if (s === '') return null

  // Whitelist znaků — defense in depth
  if (!/^[\d+\-*/.()]+$/.test(s)) return null

  // Nikdy nechceme operátorové sekvence (např. "1++2" je injection-suspicious)
  // Povolíme leading minus ("-5") a unární minus po levé závorce nebo operátoru ("(-5+3)", "5*-2")
  if (/[+\-*/.]{3,}/.test(s)) return null

  // Pokud je to už jen číslo (typicky uživatel zadal normální cenu) → zkratka
  if (/^-?\d+(\.\d+)?$/.test(s)) return parseFloat(s)

  // Pro vyhodnocení použijeme Function constructor s "use strict".
  // Bezpečné protože jsme přes regex zaručili jen aritmetické znaky — žádný identifier
  // ani literal se sem nedostane.
  try {
    // eslint-disable-next-line no-new-func
    const fn = new Function('"use strict"; return (' + s + ')')
    const r = fn()
    if (typeof r !== 'number' || !isFinite(r)) return null
    // Zaokrouhlení na 4 desetinná místa — ochrana proti FP errors (např. 0.1+0.2)
    return Math.round(r * 10000) / 10000
  } catch {
    return null
  }
}

/**
 * Vue directive `v-math` — povoluje matematické výrazy v <input> polích.
 *
 * Použití:
 *   <input v-model="item.price" v-math type="text" inputmode="decimal" />
 *
 * Behavior:
 *   - User píše "400-100" do pole
 *   - Při blur (nebo Enter) → vyhodnotí výraz → nastaví hodnotu na 300 + dispatch input event
 *     aby v-model propagoval do parent state
 *   - Pokud výraz nevalidní → ponechá původní text (uživatel uvidí svůj omyl a opraví)
 *
 * Důvod proč type="text" místo type="number":
 *   Browsery s type="number" blokují znaky `+`, `*`, `/`, závorky. inputmode="decimal"
 *   na mobile drží numerickou klávesnici.
 */
export const vMath: Directive<HTMLInputElement> = {
  mounted(el) {
    const evaluate = () => {
      const r = evalMath(el.value)
      if (r === null) return
      const formatted = String(r)
      if (el.value === formatted) return
      el.value = formatted
      // Dispatch events s `bubbles: true` — Vue v-model interní listener
      // je registrovaný na el (capture během mounted), takže input event tam
      // doletí a v-model.number ho parsuje na Number().
      el.dispatchEvent(new Event('input', { bubbles: true }))
      el.dispatchEvent(new Event('change', { bubbles: true }))
    }

    // 1) Blur — primární trigger (Tab i mouseclick mimo pole)
    el.addEventListener('blur', evaluate)

    // 2) Enter — power-user shortcut
    el.addEventListener('keydown', (e) => {
      const ev = e as KeyboardEvent
      if (ev.key === 'Enter' || ev.key === 'Tab') {
        evaluate()
      }
    })

    // 3) Debounced input — pokud user přestane psát na 800ms a obsah vypadá
    //    jako výraz (obsahuje +, -, *, / mimo začátek), zkus evaluate.
    let timeout: ReturnType<typeof setTimeout> | null = null
    el.addEventListener('input', () => {
      if (timeout) clearTimeout(timeout)
      const v = el.value
      // Trigger jen pokud obsahuje operátor (kromě leading minus pro záporná čísla)
      if (!/[+*/]|.\-/.test(v)) return
      timeout = setTimeout(evaluate, 800)
    })

    ;(el as any).__mathHandler = evaluate
  },
  beforeUnmount(el) {
    const h = (el as any).__mathHandler
    if (h) {
      el.removeEventListener('blur', h)
    }
  },
}
