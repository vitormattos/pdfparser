## PDF.js validation: aggregated fixes

## Integration branch rebuild (current)

- Rebased integration branch from master (clean reconstruction).
- Merged open fix branches in sequence: #795, #806, #812, #814, #816, #817.
- Force-pushed rebuilt branch to integration/pdfjs-fixes.
- CI status after rebuild: all checks successful (Linux + Windows matrix green).

## Post-rebuild spot revalidation

Rechecked previously problematic non-security files against current branch state:

- PDF_A-1b ... 6-1-4-t01-fail-a.pdf: pdfinfo_pages=1, parser_pages=1
- isartor-6-1-4-t01-fail-a.pdf: pdfinfo_pages=1, parser_pages=1
- veraPDF ... 6-6-2-3-2-t01-pass-c.pdf: pdfinfo_pages=1, parser_pages=1
- issue9252.pdf: pdfinfo_pages=1, parser_pages=1
- outlines_for_editor.pdf: pdfinfo_pages=5, parser_pages=5

Security-encrypted fixtures still report parser errors by design (library policy: secured PDFs unsupported).

**Objective:** Validate smalot/pdfparser against the Mozilla PDF.js corpus by comparing parser output with [pdfinfo](https://poppler.freedesktop.org/documentation.html) (Poppler reference implementation).

**Validation approach:** Run parser on each PDF, extract page count. Compare against pdfinfo page count. Files match = ok. Mismatch or parser error = issue to fix.

**Results ([PDF.js corpus](https://github.com/mozilla/pdf.js), 930 files / 929 unique hashes):**

| Status | Baseline | This branch | Δ |
|--------|----------|-------------|---|
| ✅ ok | 865 | 891 | +26 |
| ❌ parser_error | 41 | 16 | -25 |
| ⚠️ both_error | 13 | 10 | -3 |
| ℹ️ pdfinfo_error | 7 | 10 | +3 |
| 🔀 mismatch | 3 | 2 | -1 |
| **Total** | **929** | **929** | **—** |

**Success rate:** 93.1% → 95.9% (+2.8pp)

**Deduplication note:** the validation state is keyed by SHA-256, so two byte-identical files (empty.pdf and empty#hash.pdf) collapse into one effective corpus entry. The directory contains 930 PDFs, but the aggregate counts operate on 929 unique hashes.

**Note on pdfinfo_error (+3):** this is a classification shift, not a parser regression. These files are cases where parser-side failures were reduced while pdfinfo still reports malformed-input errors (e.g. Poppler syntax/xref failures).

**Additional validation:**
- [VeraPDF corpus](https://github.com/veraPDF/veraPDF-corpus): **2,907 PDFs** validated with 100% pass rate on baseline tests

**Review options:**

Maintainer can choose either path:

1. **Review & merge individual fixes**: Each focused fix reviewed and merged separately (granular approach)
2. **Fast-track integration PR**: Remove draft status and merge this consolidated PR (aggregated approach)

Both paths achieve the same end state. Choose based on review capacity and preference.
