// Client-side mirror of Formidable_Flat_Formula_Builder's tokenizer → shunting-yard →
// RPN evaluator (class-formula-builder.php), used only for the live formula tester in
// the calculated-columns editor — never to actually compute saved report data (that
// always runs server-side in PHP). Ported near-verbatim from the pre-Svelte admin.php
// inline script; kept deliberately eval-free, matching the plugin's no-eval guarantee.
//
// CALC_FUNCTIONS (name -> {min,max} arity) is injected from PHP via bootstrap data
// (window.ffapiAdmin.calcFunctions) so the two can't drift apart — call setCalcFunctions()
// once at startup before evaluate() is used.

let CALC_FUNCTIONS = {};

export function setCalcFunctions(fns) {
  CALC_FUNCTIONS = fns || {};
}

function calcCoerceNum(v) {
  if (v === null || v === undefined || v === '') return 0;
  if (typeof v === 'number') return v;
  if (typeof v === 'string') {
    if (v.trim() !== '' && !isNaN(Number(v))) return Number(v);
    const cleaned = v.replace(/[^0-9.\-]/g, '');
    if (cleaned !== '' && !isNaN(parseFloat(cleaned))) return parseFloat(cleaned);
  }
  throw new Error('non-numeric value "' + String(v) + '"');
}

function calcCoerceStr(v) {
  if (v === null || v === undefined) return '';
  if (typeof v === 'number') {
    if (!isFinite(v)) throw new Error('non-finite result');
    return String(parseFloat(v.toFixed(6)));
  }
  return String(v);
}

function calcTokenize(f) {
  const toks = [];
  let i = 0, prev = null;
  while (i < f.length) {
    const c = f[i];
    if (/\s/.test(c)) { i++; continue; }

    if (c === '[') {
      const end = f.indexOf(']', i + 1);
      if (end === -1) throw new Error('unterminated [field] reference');
      const label = f.slice(i + 1, end);
      if (!label) throw new Error('empty [] reference');
      toks.push(['ref', label]); prev = 'val'; i = end + 1; continue;
    }

    if (c === '"' || c === "'") {
      let j = i + 1, buf = '', closed = false;
      while (j < f.length) {
        if (f[j] === c) {
          if (f[j + 1] === c) { buf += c; j += 2; continue; }
          closed = true; j++; break;
        }
        buf += f[j]; j++;
      }
      if (!closed) throw new Error('unterminated text literal');
      toks.push(['str', buf]); prev = 'val'; i = j; continue;
    }

    if (/[0-9]/.test(c) || (c === '.' && /[0-9]/.test(f[i + 1] || ''))) {
      let j = i, dot = false;
      while (j < f.length && (/[0-9]/.test(f[j]) || (f[j] === '.' && !dot))) {
        if (f[j] === '.') dot = true;
        j++;
      }
      toks.push(['num', parseFloat(f.slice(i, j))]); prev = 'val'; i = j; continue;
    }

    if (/[A-Za-z_]/.test(c)) {
      let j = i;
      while (j < f.length && /[A-Za-z0-9_]/.test(f[j])) j++;
      const word = f.slice(i, j).toUpperCase();
      if (!CALC_FUNCTIONS[word]) throw new Error('unknown function "' + word + '"');
      toks.push(['func', word]); prev = 'func'; i = j; continue;
    }

    if (c === '(') { toks.push(['lparen']); prev = 'lparen'; i++; continue; }
    if (c === ')') { toks.push(['rparen']); prev = 'val';    i++; continue; }
    if (c === ',') { toks.push(['comma']);  prev = 'comma';  i++; continue; }

    if ('+-*/&'.includes(c)) {
      if ((c === '-' || c === '+') && (prev === null || prev === 'op' || prev === 'lparen' || prev === 'comma')) {
        if (c === '-') { toks.push(['op', 'u-']); prev = 'op'; }
        i++; continue;
      }
      toks.push(['op', c]); prev = 'op'; i++; continue;
    }

    throw new Error('unexpected character "' + c + '"');
  }
  return toks;
}

function calcCompile(formula) {
  const toks = calcTokenize(formula);
  const out = [], ops = [], argc = [];
  const prec = { 'u-': 5, '*': 4, '/': 4, '+': 3, '-': 3, '&': 2 };
  const markArg = () => { if (argc.length && argc[argc.length - 1] === 0) argc[argc.length - 1] = 1; };

  for (const tok of toks) {
    const t = tok[0];
    if (t === 'num' || t === 'ref' || t === 'str') { markArg(); out.push(tok); }
    else if (t === 'func') { markArg(); ops.push(tok); argc.push(0); }
    else if (t === 'comma') {
      let matched = false;
      for (let k = ops.length - 1; k >= 0; k--) {
        if (ops[k][0] === 'lparen') { matched = true; break; }
        out.push(ops.pop());
      }
      if (!matched || !argc.length) throw new Error('misplaced comma (only valid between function arguments)');
      argc[argc.length - 1]++;
    }
    else if (t === 'op') {
      const o1 = tok[1];
      while (ops.length) {
        const top = ops[ops.length - 1];
        if (top[0] !== 'op') break;
        const o2 = top[1];
        if ((o1 === 'u-' && prec[o2] > prec[o1]) || (o1 !== 'u-' && prec[o2] >= prec[o1])) out.push(ops.pop());
        else break;
      }
      ops.push(tok);
    }
    else if (t === 'lparen') ops.push(tok);
    else if (t === 'rparen') {
      let matched = false;
      while (ops.length) {
        const top = ops.pop();
        if (top[0] === 'lparen') { matched = true; break; }
        out.push(top);
      }
      if (!matched) throw new Error('mismatched parentheses');
      if (ops.length && ops[ops.length - 1][0] === 'func') {
        const fn = ops.pop();
        const n  = argc.pop();
        const meta = CALC_FUNCTIONS[fn[1]];
        if (n < meta.min || (meta.max !== null && n > meta.max)) {
          const exp = meta.max === null ? 'at least ' + meta.min
                    : (meta.min === meta.max ? String(meta.min) : meta.min + '–' + meta.max);
          throw new Error(fn[1] + '() takes ' + exp + ' argument(s), got ' + n);
        }
        out.push(['func', fn[1], n]);
      }
    }
  }
  while (ops.length) {
    const top = ops.pop();
    if (top[0] === 'lparen' || top[0] === 'rparen') throw new Error('mismatched parentheses');
    if (top[0] === 'func') throw new Error('missing closing ")" after ' + top[1] + '(');
    out.push(top);
  }
  if (!out.length) throw new Error('empty formula');
  return out;
}

function calcApplyFn(name, args) {
  const nums = () => args.map(calcCoerceNum);
  const strs = () => args.map(calcCoerceStr);
  switch (name) {
    case 'ROUND': {
      const n = nums();
      const d = Math.max(0, Math.min(10, n.length > 1 ? Math.trunc(n[1]) : 0));
      const p = Math.pow(10, d);
      return Math.round(n[0] * p) / p;
    }
    case 'ABS':    return Math.abs(calcCoerceNum(args[0]));
    case 'MIN':    return Math.min(...nums());
    case 'MAX':    return Math.max(...nums());
    case 'SUM':    return nums().reduce((a, b) => a + b, 0);
    case 'LEN':    return calcCoerceStr(args[0]).length;
    case 'CONCAT': return strs().join('');
    case 'UPPER':  return calcCoerceStr(args[0]).toUpperCase();
    case 'LOWER':  return calcCoerceStr(args[0]).toLowerCase();
    case 'TRIM':   return calcCoerceStr(args[0]).trim();
  }
  throw new Error('unknown function "' + name + '"');
}

/**
 * knownFields (optional Set): the universe of valid field names. When provided, a
 * reference is "unknown" only if it isn't in this set — NOT merely if it's absent from
 * the single sample row. A valid field that happens to be blank in the sample row is
 * then treated as empty (coerced to 0/"") instead of falsely reported "Unknown field".
 */
export function evaluate(formula, rowData, knownFields) {
  if (!formula || !rowData) return { error: 'No formula or data' };

  let rpn;
  try { rpn = calcCompile(formula); }
  catch (e) { return { error: e.message }; }

  for (const tok of rpn) {
    if (tok[0] !== 'ref') continue;
    const known = knownFields ? knownFields.has(tok[1]) : (tok[1] in rowData);
    if (!known) return { error: 'Unknown field [' + tok[1] + ']' };
  }

  const stack = [];
  try {
    for (const tok of rpn) {
      const t = tok[0];
      if (t === 'num' || t === 'str') stack.push(tok[1]);
      else if (t === 'ref') stack.push(rowData[tok[1]]);
      else if (t === 'func') {
        if (stack.length < tok[2]) throw new Error('malformed expression');
        stack.push(calcApplyFn(tok[1], tok[2] ? stack.splice(-tok[2]) : []));
      }
      else if (t === 'op') {
        const op = tok[1];
        if (op === 'u-') {
          if (!stack.length) throw new Error('malformed expression');
          stack.push(-calcCoerceNum(stack.pop()));
        } else {
          if (stack.length < 2) throw new Error('malformed expression');
          const b = stack.pop(), a = stack.pop();
          if (op === '&') { stack.push(calcCoerceStr(a) + calcCoerceStr(b)); continue; }
          const x = calcCoerceNum(a), y = calcCoerceNum(b);
          if (op === '+') stack.push(x + y);
          if (op === '-') stack.push(x - y);
          if (op === '*') stack.push(x * y);
          if (op === '/') {
            if (y === 0) throw new Error('division by zero');
            stack.push(x / y);
          }
        }
      }
    }
  } catch (e) {
    return { error: e.message };
  }

  if (stack.length !== 1) return { error: 'malformed expression' };
  const result = stack[0];
  if (typeof result === 'string') return { result };
  if (!isFinite(result)) return { error: 'non-finite result (overflow or /0)' };
  return { result: Math.round(result * 1000) / 1000 };
}
