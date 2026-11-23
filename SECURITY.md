English | [Русский](SECURITY.ru.md)

# Security policy

## Supported versions

Security fixes go to the `main` branch and to the latest release. Before 1.0.0 only the latest minor line receives fixes.

## Report a vulnerability

Report vulnerabilities privately through GitHub Private Vulnerability Reporting. Open the **Security** tab of the [it-koval/cms](https://github.com/it-koval/cms) repository and select **Report a vulnerability**. Do not open a public issue or pull request for a security problem, and do not post it in discussions.

Include:

- what is affected (the admin panel, the JSON API, public pages, the MCP server, or the install commands) and the version or commit;
- how to reproduce the problem, ideally on the smallest site configuration that shows it;
- what an attacker gains.

We read every report, reply in the private advisory, and keep you informed about the fix. We do not promise response times before 1.0.0. When the fix is released, we credit you if you want.

## Scope

The bundle ships the admin panel, the JSON API under the admin URL, public routes (pages, form requests, `/healthz`, `/mcp`, `/sitemap.xml`, `/robots.txt`, `/llms.txt`, the IndexNow key file, and image copies), and console commands. Report problems in your site code, in Symfony, or in other dependencies to their maintainers. If the bundle configuration makes such a problem worse, tell us too.
