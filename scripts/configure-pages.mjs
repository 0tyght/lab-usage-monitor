import { spawnSync } from 'node:child_process';
import { assertRepository, ROOT } from './online-lib.mjs';

// One-time setup using the existing Git Credential Manager session. No tokens
// are printed, stored in files, or passed on the command line.
try {
  assertRepository();
  const result = spawnSync('git', ['credential', 'fill'], {
    cwd: ROOT, windowsHide: true, encoding: 'utf8',
    input: 'protocol=https\nhost=github.com\npath=0tyght/lab-usage-monitor.git\n\n',
    env: { ...process.env, GIT_TERMINAL_PROMPT: '0', GCM_INTERACTIVE: 'never' },
  });
  const credential = result.stdout?.split(/\r?\n/).find((line) => line.startsWith('password='))?.slice(9);
  if (result.status !== 0 || !credential) throw new Error('No existing GitHub credential. Sign in to GitHub using Git Credential Manager first.');
  const headers = { Accept: 'application/vnd.github+json', Authorization: `Bearer ${credential}`, 'X-GitHub-Api-Version': '2026-03-10' };
  const endpoint = 'https://api.github.com/repos/0tyght/lab-usage-monitor/pages';
  let response = await fetch(endpoint, { headers, signal: AbortSignal.timeout(15000) });
  if (response.status === 404) {
    if (!process.argv.includes('--apply')) throw new Error('GitHub Pages is not configured. Run this script with --apply to enable workflow-based Pages for this repository.');
    response = await fetch(endpoint, { method: 'POST', headers: { ...headers, 'Content-Type': 'application/json' }, body: JSON.stringify({ build_type: 'workflow' }), signal: AbortSignal.timeout(15000) });
  }
  if (!response.ok) throw new Error(`GitHub Pages setup returned HTTP ${response.status}. Check repository Pages/Administration permissions; no credential was printed.`);
  const pages = await response.json();
  if (pages.build_type !== 'workflow') throw new Error('Existing Pages uses a different publishing source. Select GitHub Actions in repository Settings > Pages; it was not changed automatically.');
  console.log(`GitHub Pages configured for Actions: ${pages.html_url}`);
} catch (error) {
  console.error(error.message);
  process.exitCode = 1;
}
