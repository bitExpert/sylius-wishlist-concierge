import { registerAll } from './webmcp/registry.js';

// Register WebMCP tools when the DOM is ready. Registration lives here (the
// entrypoint) rather than in registry.js so that modules importing TOOLS from
// registry.js (e.g. the Stimulus toolbox controller) don't trigger registration
// as an import side-effect.
if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', registerAll);
} else {
    // slight delay to ensure TestApplication scripts loaded
    setTimeout(registerAll, 100);
}
