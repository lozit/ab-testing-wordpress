# Vendored third-party JS

Files in this folder are bundled with the plugin so the WordPress.org
plugin guidelines (rule #5: "Trying to remotely load code") are satisfied
— nothing is fetched from a CDN at runtime.

## chart.umd.min.js

- **Library**: Chart.js
- **Version**: 4.4.1
- **Source**: https://www.chartjs.org/ — https://github.com/chartjs/Chart.js
- **License**: MIT (https://github.com/chartjs/Chart.js/blob/master/LICENSE.md)
- **Original file**: https://cdn.jsdelivr.net/npm/chart.js@4.4.1/dist/chart.umd.min.js
- **Used by**: `includes/Admin/Admin.php` — admin-only, on the A/B Tests list view, to render the conversion-rate timeline per URL.

### MIT License (Chart.js)

```
The MIT License (MIT)

Copyright (c) 2014-2022 Chart.js Contributors

Permission is hereby granted, free of charge, to any person obtaining a copy
of this software and associated documentation files (the "Software"), to deal
in the Software without restriction, including without limitation the rights
to use, copy, modify, merge, publish, distribute, sublicense, and/or sell
copies of the Software, and to permit persons to whom the Software is
furnished to do so, subject to the following conditions:

The above copyright notice and this permission notice shall be included in
all copies or substantial portions of the Software.

THE SOFTWARE IS PROVIDED "AS IS", WITHOUT WARRANTY OF ANY KIND, EXPRESS OR
IMPLIED, INCLUDING BUT NOT LIMITED TO THE WARRANTIES OF MERCHANTABILITY,
FITNESS FOR A PARTICULAR PURPOSE AND NONINFRINGEMENT. IN NO EVENT SHALL THE
AUTHORS OR COPYRIGHT HOLDERS BE LIABLE FOR ANY CLAIM, DAMAGES OR OTHER
LIABILITY, WHETHER IN AN ACTION OF CONTRACT, TORT OR OTHERWISE, ARISING FROM,
OUT OF OR IN CONNECTION WITH THE SOFTWARE OR THE USE OR OTHER DEALINGS IN
THE SOFTWARE.
```

### Updating

To update Chart.js to a newer version :

```bash
curl -sL https://cdn.jsdelivr.net/npm/chart.js@<NEW_VERSION>/dist/chart.umd.min.js \
  -o assets/js/vendor/chart.umd.min.js
```

Then bump the version string in `includes/Admin/Admin.php` (the `wp_enqueue_script` call) and update the version mentioned above.
