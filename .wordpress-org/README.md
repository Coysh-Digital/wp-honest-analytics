# WordPress.org directory assets

The banner, icon and screenshots the plugin directory shows. They are not part
of the plugin and are never shipped inside it - the directory keeps them in a
separate `assets/` folder in its own SVN repository, and the deploy workflow
copies this directory there.

Nothing here yet. What is needed, when there is a designer to hand:

| File | Size | Where it appears |
|---|---|---|
| `icon-128x128.png` | 128x128 | Search results and the plugins list |
| `icon-256x256.png` | 256x256 | The same, on a high-density screen |
| `banner-772x250.png` | 772x250 | The top of the plugin page |
| `banner-1544x500.png` | 1544x500 | The same, on a high-density screen |
| `screenshot-1.png` … | any, consistent | The Screenshots tab, in `readme.txt` order |

Screenshot numbers match the numbered lines under `== Screenshots ==` in
`readme.txt`. There are already real ones in `docs/screenshots/desktop/` in the
development repository; they need cropping and renaming rather than taking
again.

A plugin can be submitted without any of these. It looks unfinished without at
least an icon.
