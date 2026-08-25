# WordPress.org directory assets

The banner, icon and screenshots the plugin directory shows. They are not part
of the plugin and are never shipped inside it - the directory keeps them in a
separate `assets/` folder in its own SVN repository, and the listing workflow
copies this directory there.

| File | Size | Where it appears | Here? |
|---|---|---|---|
| `icon-128x128.png` | 128x128 | Search results and the plugins list | yes |
| `icon-256x256.png` | 256x256 | The same, on a high-density screen | yes |
| `banner-772x250.png` | 772x250 | The top of the plugin page | yes |
| `banner-1544x500.png` | 1544x500 | The same, on a high-density screen | yes |
| `screenshot-1.png` … | any, consistent | The Screenshots tab, in `readme.txt` order | **no** |

## The icon and the banner

Drawn by `bin/directory-assets.py`, not by hand. Run it to change them:

```bash
python3 bin/directory-assets.py
```

The mark is the marketing site's own logo - the chart geometry is
`public/favicon.svg` drawn at a larger size, the palette comes from
`src/styles/tokens.css`, and the wordmark is set in the same serif the site
falls back to. The banner follows the lockup the site's share image uses: the
teal rule, then the mark and the name together, then one line of sans.

Somebody changing the logo on the site should change it here in the same
sitting, or the directory quietly shows last year's brand. A designer
replacing these outright should replace the script as well, or the next run
overwrites their work.

## The screenshots

Still to do. Numbers match the numbered lines under `== Screenshots ==` in
`readme.txt`, which currently lists seven, all of them free-edition screens:

| # | Screen |
|---|---|
| 1 | Dashboard |
| 2 | Real-time |
| 3 | Pages |
| 4 | Page detail |
| 5 | Sources |
| 6 | Privacy |
| 7 | Settings |

`bin/screenshots.mjs` captures against the demo site, which runs this working
tree and therefore behaves as **Pro**. Photographing that and putting it on the
free listing would show reports the download does not contain, which is the
kind of thing the directory treats as misleading. Capture against an install of
the Lite zip instead.

A plugin can go live without screenshots. It cannot go live without an icon
and look like anything but an abandoned one.
