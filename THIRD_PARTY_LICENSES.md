# Third-Party Components and Licenses

cwhpanel is proprietary software (see `LICENSE` and `NOTICE`). It includes or
links the components below, which remain under their own licenses. Those
licenses prevail over the proprietary terms for the components concerned.

## Bundled source / assets

### qrcodegen.js — MIT

`public/assets/js/qrcodegen.js`
Copyright (c) Project Nayuki — https://www.nayuki.io/page/qr-code-generator-library

```
Permission is hereby granted, free of charge, to any person obtaining a copy of
this software and associated documentation files (the "Software"), to deal in
the Software without restriction, including without limitation the rights to
use, copy, modify, merge, publish, distribute, sublicense, and/or sell copies of
the Software, and to permit persons to whom the Software is furnished to do so,
subject to the following conditions:

The above copyright notice and this permission notice shall be included in all
copies or substantial portions of the Software.

THE SOFTWARE IS PROVIDED "AS IS", WITHOUT WARRANTY OF ANY KIND, EXPRESS OR
IMPLIED, INCLUDING BUT NOT LIMITED TO THE WARRANTIES OF MERCHANTABILITY,
FITNESS FOR A PARTICULAR PURPOSE AND NONINFRINGEMENT. IN NO EVENT SHALL THE
AUTHORS OR COPYRIGHT HOLDERS BE LIABLE FOR ANY CLAIM, DAMAGES OR OTHER
LIABILITY, WHETHER IN AN ACTION OF CONTRACT, TORT OR OTHERWISE, ARISING FROM,
OUT OF OR IN CONNECTION WITH THE SOFTWARE OR THE USE OR OTHER DEALINGS IN THE
SOFTWARE.
```

### Roundcube SSO plugin — GPL-3.0-or-later (Roundcube plugin exception)

`deploy/roundcube/plugins/cwhpanel_sso/`

This is a Roundcube plugin and a **separate program**: it is not covered by the
proprietary grant that applies to the rest of cwhpanel. Its source is included
in this distribution, and a copy of the GNU General Public License version 3 is
included at `deploy/roundcube/plugins/cwhpanel_sso/COPYING`.
Upstream: https://roundcube.net/ — GPL-3.0: https://www.gnu.org/licenses/gpl-3.0.html

## Rust crates linked into the agent binary

The privileged agent (`agent/`) is a statically linked Rust binary. Every crate
below is used under its permissive license; where a crate offers a choice we
rely on the **MIT** option, and the MIT text above (with the respective
authors' copyright notices as recorded in their source distributions) applies.

| Crate | Version | License |
|---|---|---|
| itoa | 1.0.18 | MIT OR Apache-2.0 |
| libc | 0.2.189 | MIT OR Apache-2.0 |
| memchr | 2.8.3 | Unlicense OR MIT (MIT elected) |
| proc-macro2 | 1.0.107 | MIT OR Apache-2.0 |
| quote | 1.0.47 | MIT OR Apache-2.0 |
| serde | 1.0.229 | MIT OR Apache-2.0 |
| serde_core | 1.0.229 | MIT OR Apache-2.0 |
| serde_derive | 1.0.229 | MIT OR Apache-2.0 |
| serde_json | 1.0.151 | MIT OR Apache-2.0 |
| syn | 3.0.4 | MIT OR Apache-2.0 |
| unicode-ident | 1.0.24 | (MIT OR Apache-2.0) AND Unicode-3.0 |
| zmij | 1.0.23 | MIT |

`unicode-ident` additionally embeds Unicode data under the **Unicode License
v3** (https://www.unicode.org/license.txt); that notice applies to the embedded
data.

If you distribute the agent binary, ship this file (and `NOTICE`) with it.

## Operating-system packages (not distributed)

The deployment scripts install distribution packages with the OS package
manager — for example nginx, MariaDB, Postfix, Dovecot, PowerDNS, vsftpd, PHP
(Remi), Roundcube, phpMyAdmin, ClamAV, fail2ban. These are not bundled with
cwhpanel; they are installed on the target host and remain under their own
licenses and notices (for example Roundcube and phpMyAdmin are GPL-2/3, nginx
is BSD-2-Clause, MariaDB is GPL-2.0, Postfix is IPL/EPL, Dovecot is
LGPL-2.1/BSD).
