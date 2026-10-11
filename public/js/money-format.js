// Standalone Indonesian-style money formatter for plain form inputs (thousands
// ".", decimals ","), e.g. "1.500.000". Extracted subset of the same live
// input-formatting behavior already used across the rental pages
// (rentals-index.js's rentalMoneyParse/rentalApplyLiveMoneyFormatting), kept
// separate here since that file is large and tightly coupled to the rentals
// page's own DOM/state — this is a small, dependency-free version any plain
// text money input can use. Server-side counterpart: App\Support\MoneyInput.
(function () {
    var moneyFormatter = new Intl.NumberFormat('id-ID', { minimumFractionDigits: 0, maximumFractionDigits: 2 });

    function moneyParse(str) {
        if (str == null || String(str).trim() === '') return NaN;
        if (typeof str === 'number') return Number.isFinite(str) ? str : NaN;
        var s = String(str).trim();
        if (s.indexOf(',') !== -1) {
            s = s.replace(/\./g, '').replace(',', '.');
        } else {
            var dots = (s.match(/\./g) || []).length;
            if (dots > 1) {
                s = s.replace(/\./g, '');
            } else if (dots === 1) {
                var afterDot = s.slice(s.lastIndexOf('.') + 1);
                if (afterDot.length === 3) s = s.replace(/\./g, '');
            }
        }
        var n = parseFloat(s);
        return Number.isFinite(n) ? n : NaN;
    }

    function moneyFormatNum(n) {
        if (!Number.isFinite(n)) return '';
        return moneyFormatter.format(n);
    }

    function digitCountBefore(val, caret) {
        var c = 0;
        var lim = Math.min(caret | 0, val.length);
        for (var i = 0; i < lim; i++) {
            if (/\d/.test(val.charAt(i))) c++;
        }
        return c;
    }

    function formatIntGrouping(digits) {
        if (!digits) return '';
        var out = '';
        var cnt = 0;
        for (var i = digits.length - 1; i >= 0; i--) {
            if (cnt > 0 && cnt % 3 === 0) out = '.' + out;
            out = digits.charAt(i) + out;
            cnt++;
        }
        return out;
    }

    function caretPos(newVal, intDigitsBefore, fracDigitsBefore, caretRightAfterComma) {
        var i = 0;
        var intSeen = 0;
        for (; i < newVal.length; i++) {
            var ch = newVal.charAt(i);
            if (ch === ',') break;
            if (/\d/.test(ch)) {
                intSeen++;
                if (intDigitsBefore > 0 && intSeen >= intDigitsBefore) { i++; break; }
                if (intDigitsBefore === 0) break;
            }
        }
        if (fracDigitsBefore <= 0 && !caretRightAfterComma) return Math.min(i, newVal.length);
        while (i < newVal.length && newVal.charAt(i) !== ',') i++;
        if (caretRightAfterComma && fracDigitsBefore <= 0) return Math.min(i + 1, newVal.length);
        if (i < newVal.length && newVal.charAt(i) === ',') i++;
        var fracSeen = 0;
        for (; i < newVal.length; i++) {
            if (/\d/.test(newVal.charAt(i))) {
                fracSeen++;
                if (fracSeen >= fracDigitsBefore) return i + 1;
            }
        }
        return newVal.length;
    }

    function applyLiveFormatting(el) {
        var raw = el.value;
        var caret = el.selectionStart != null ? el.selectionStart : raw.length;
        var commaIdx = raw.indexOf(',');
        var intEnd = commaIdx === -1 ? raw.length : commaIdx;
        var intDigitsBefore = digitCountBefore(raw.slice(0, intEnd), Math.min(caret, intEnd));
        var fracDigitsBefore = 0;
        var caretRightAfterComma = false;
        if (commaIdx !== -1 && caret > commaIdx) {
            fracDigitsBefore = digitCountBefore(raw.slice(commaIdx + 1), caret - commaIdx - 1);
        }
        if (commaIdx !== -1 && caret === commaIdx + 1) caretRightAfterComma = true;
        var intRaw = commaIdx === -1 ? raw : raw.slice(0, commaIdx);
        var fracRaw = commaIdx === -1 ? null : raw.slice(commaIdx + 1);
        intRaw = intRaw.replace(/\./g, '').replace(/\D/g, '');
        if (fracRaw !== null) fracRaw = fracRaw.replace(/\D/g, '').slice(0, 2);
        var hasComma = commaIdx !== -1;
        var intFmt = formatIntGrouping(intRaw);
        if (hasComma && intRaw === '') intFmt = '0';
        var newVal = hasComma ? intFmt + ',' + (fracRaw !== null ? fracRaw : '') : intFmt;
        var newCaret = caretPos(newVal, intDigitsBefore, fracDigitsBefore, caretRightAfterComma);
        el.value = newVal;
        el.setSelectionRange(newCaret, newCaret);
    }

    /** Attaches live "1.500.000" thousands-grouping formatting to a plain text input. */
    function bindMoneyThousandsInput(el) {
        if (!el) return;
        el.addEventListener('input', function () { applyLiveFormatting(el); });
        el.addEventListener('blur', function () {
            var v = el.value.trim();
            if (v === '') return;
            var n = moneyParse(v);
            if (Number.isFinite(n)) el.value = moneyFormatNum(n);
        });
    }

    window.moneyParse = moneyParse;
    window.moneyFormatNum = moneyFormatNum;
    window.bindMoneyThousandsInput = bindMoneyThousandsInput;
})();
