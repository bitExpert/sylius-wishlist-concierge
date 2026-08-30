# WebMCP Playwright-CLI Manual (https + persistent profile)

This manual explains how to verify the Wishlist Concierge WebMCP (`document.modelContext`) on `https://wishlist-concierge.ddev.site/en_US/` using only the `playwright-cli` skill.

## 1. Prerequisites

**Browser:** `Google Chrome 152.0.7977.64` at `/opt/google/chrome/chrome` (installed via `npx playwright install chrome` or apt). `chromium` bundle alone does not expose WebMCP even with flags.

**Config:** `.playwright/cli.config.json` must be:
```json
{
  "ignoreHTTPSErrors": true,
  "browser": {
    "browserName": "chromium",
    "launchOptions": {
      "channel": "chrome",
      "args": [
        "--enable-features=WebMCP",
        "--ignore-certificate-errors",
        "--unsafely-treat-insecure-origin-as-secure=https://wishlist-concierge.ddev.site"
      ]
    }
  }
}
```
- `ignoreHTTPSErrors:true` + `--ignore-certificate-errors` bypass ddev self-signed cert for `https`.
- `--unsafely-treat-insecure-origin-as-secure` makes `isSecureContext===true` for `https` (required; `http` → `false` → `WebMCP unavailable`).
- `channel:chrome` forces real Chrome, not `chromium` headless shell.

**Persistent profile:** `/tmp/webmcp-profile` must contain `Local State` with:
```json
"browser": { "enabled_labs_experiments": ["enable-webmcp-testing@1","temporary-unexpire-flags-m150@1","temporary-unexpire-flags-m151@1"] }
```
Create once via `chrome://flags`:
1. `playwright-cli open https://wishlist-concierge.ddev.site/en_US/ --profile=/tmp/webmcp-profile`
2. `playwright-cli goto chrome://flags/#enable-webmcp-testing` → Select `WebMCP for testing` → `Enabled`
3. Also enable `Temporarily unexpire M150/M151` → `Enabled` (flag expired in 152, needs unexpire).
4. Check `cat /tmp/webmcp-profile/Local\ State | grep enable-webmcp` shows the three entries, and `chrome://version` shows `--flag-switches-begin --enable-features=WebMCP,UnexpireFlagsM150,UnexpireFlagsM151`.
5. `playwright-cli kill-all` then reopen — flags persist because profile is persistent. **Every `open` must use `--profile=/tmp/webmcp-profile`**, otherwise a random `/tmp/playwright_chromiumdev_profile-*` is used and flags are lost.

## 2. Core `playwright-cli` commands

| Command | Purpose | Example |
|---------|---------|---------|
| `open [url] --profile=...` | Start browser + navigate | `playwright-cli open https://wishlist-concierge.ddev.site/en_US/ --profile=/tmp/webmcp-profile` |
| `kill-all` / `close` | Restart daemon / close browser (required after config or flag change) | `playwright-cli kill-all` |
| `goto <url>` | Navigate | `playwright-cli goto chrome://version` |
| `snapshot` | YAML snapshot with refs (`e123`) | `playwright-cli snapshot \| grep WebMCP` |
| `find "text"` / `find --regex "/.../i"` | Search snapshot | `playwright-cli find "WebMCP for testing"` |
| `select <ref> "Enabled"` | Choose flag value | `playwright-cli select f1e37 "Enabled"` |
| `click <ref>` | Click button (e.g., `Neu starten`) | `playwright-cli click f1e6378` |
| `eval "<js>"` | `page.evaluate(() => (<js>))` | `playwright-cli eval "'modelContext' in document"` |
| `--raw eval` | Same but raw JSON output for `jq` | `playwright-cli --raw eval "JSON.stringify({isSecure:isSecureContext})" | jq` |
| `run-code "async page => {...}"` | Full Playwright JS | `playwright-cli run-code "async page => { const t=await page.evaluate(...); console.log(t) }"` |
| `console [level]` | Show browser console (`warning` shows `[WebMCP] document.modelContext not available`) | `playwright-cli console warning` |

**Tips:**
- Always use `https`, not `http`. `http` → `isSecure:false` → WebMCP disabled.
- After any `.playwright/cli.config.json` or `Local State` edit, run `kill-all`.
- Use `--raw` for machine-readable JSON; without `--raw` output is pretty-wrapped.
- `eval` expects a single JS expression; for async use `(async()=>{...})()`.

## 3. Verifying WebMCP is enabled

```bash
playwright-cli kill-all
playwright-cli open https://wishlist-concierge.ddev.site/en_US/ --profile=/tmp/webmcp-profile
sleep 3
playwright-cli snapshot | grep -A2 WebMCP          # expect: WebMCP: 8 tools ready
playwright-cli --raw eval "JSON.stringify({isSecure:isSecureContext, hasDoc:'modelContext' in document})"
# {"isSecure":true,"hasDoc":true}
playwright-cli console | grep "registered"          # 8× [WebMCP] registered ...
playwright-cli --raw eval "(async()=>{const t=await document.modelContext.getTools(); return t.map(x=>x.name).join(',')})()"
# product.get_details,product.search_themed,wishlist.add_item,...
```

If you see `WebMCP unavailable — enable flag` or `hasDoc:false`, check:
1. `cat /tmp/webmcp-profile/Local\ State | grep enable-webmcp`
2. `playwright-cli goto chrome://version` → `Befehlszeile` should contain `--flag-switches-begin --enable-features=WebMCP`
3. `ps aux | grep chrome | grep user-data-dir` should show `/tmp/webmcp-profile`

## 4. Running the full test suite

```bash
./scripts/webmcp-playwright-cli-test.sh
```
The script does `kill-all` → `open https --profile` → discovery (8 tools, `isSecure`, `getTools`, console) → sequential `fetch` calls for each tool (unique wishlist name `Playwright-Dino-<ts>`) → final `wishlist.move_to_cart` with mocked `window.confirm`. Exit 0 = all PASS.

## 5. Troubleshooting

- `net::ERR_CERT_AUTHORITY_INVALID` on `https` → `ignoreHTTPSErrors` not loaded → `kill-all` and ensure `.playwright/cli.config.json` has `ignoreHTTPSErrors:true` and `--ignore-certificate-errors`.
- `WebMCP unavailable` after correct flags → was `http` or profile not persistent → ensure `https` + `--profile=/tmp/webmcp-profile` every `open`.
- `Browser 'chrome' is not found` → `channel:chrome` requires `/opt/google/chrome/chrome` → reinstall Chrome.
- `Local State` flags disappear after `open` → used random profile → always pass `--profile`.

## 6. References

- `assets/shop/webmcp/registry.js:1` — registers 8 tools, `getBaseUrl()` uses `/{en_US|de_DE|fr_FR}`.
- `chrome://flags/#enable-webmcp-testing` → `Enables the WebMCP API` (needs M150/M151 unexpire on 152).
- `https://pptr.dev/next/guides/webmcp` → `--enable-features=WebMCP`
- `https://developer.chrome.com/docs/ai/webmcp` — local flag for development.
