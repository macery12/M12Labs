# M12Labs

[![Latest Release](https://img.shields.io/github/v/release/macery12/m12labs?style=for-the-badge)](https://github.com/macery12/m12labs/releases)
[![Stars](https://img.shields.io/github/stars/macery12/m12labs?style=for-the-badge)](https://github.com/macery12/m12labs/stargazers)
[![Forks](https://img.shields.io/github/forks/macery12/m12labs?style=for-the-badge)](https://github.com/macery12/m12labs/network)

**Game panel and billing system: fast, secure, and customizable**

---

## Overview

M12Labs is a game server management and billing platform, forked from the Jexactyl/Pterodactyl panel. It's since diverged into its own product: a rebuilt V2 interface, a new extension architecture, and a billing system with native Stripe and PayPal support.

## Features

- V2 UI: full frontend rebuild (React + TypeScript on Laravel) with a new theme system and multi-language support
- Extension system: signed extensions installed from the panel, built on a PHP SDK for servers, files, settings and secrets. They can add server and admin pages, hooks, queued and scheduled jobs, and their own database tables, Every install shows the privileges an extension asks for before you approve it.
- SSO login via Discord and Google, plus Two-Factor Authentication
- Integrated billing (Stripe + PayPal)
- Landing page builder with a public storefront API for plans and servers
- Per-feature toggles to enable or disable modules panel-wide
- Migration tooling to import existing Pterodactyl/Jexactyl/JexPanel installs (see [Notes](#notes) below)
- Authentication and security: session/device management, audit logging

## Requirements

- PHP 8.4 with the `common`, `cli`, `gd`, `mysql`, `mbstring`, `bcmath`, `xml`, `fpm`, `curl` and `zip` extensions
- MariaDB or MySQL
- Redis (cache, sessions and the queue all run on it)
- Node.js 20.19+ and pnpm 10 (extension installs rebuild the interface on the server)
- systemd, to keep Horizon (the queue worker) running
- cron, for `php artisan schedule:run` every minute

## Notes

- **Migration tooling**: it works, but still needs more real-world testing. If you're running an existing Pterodactyl, Jexactyl, or JexPanel install, reach out on [Discord](https://discord.gg/fVJZtqKYrc); help testing the importer against a real install is welcome.

## Useful Links

- Website: [m12labs.net](https://m12labs.net)
- Documentation: [docs.m12labs.net](https://docs.m12labs.net)
- GitHub Repository: [macery12/m12labs](https://github.com/macery12/m12labs)
- M12Labs Discord: [discord.gg/fVJZtqKYrc](https://discord.gg/fVJZtqKYrc)

## Contribution

Contributions are welcome. See `CONTRIBUTING.md` and join discussions via Discord or GitHub issues.

## AI & Security Disclosure

This project has used AI-assisted development from early on, starting with GitHub Copilot and now built primarily with Claude Code.

Maintainers review and test all AI-assisted output before it's merged or released. No automated system is solely responsible for production code or security decisions.