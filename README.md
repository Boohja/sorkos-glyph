# Glyph

Minimal PHP web app for creating clean SVG icon sprites and saved icon fonts without npm, Composer, webpack, or a frontend build step.

Glyph lets guests build downloadable SVG sprites in one browser session. Signed-in users can save icon projects and generate versioned WOFF2, WOFF, CSS, and cross-origin font CDN assets.

## Setup

Copy the example configuration files:

```bash
cp config/app.example.php config/app.php
cp config/db.example.php config/db.php
```

Update the copied files with your local URL, auth settings, and MySQL credentials.

Create a MySQL database and import the schema:

```bash
mysql -u glyph -p glyph < docs/sql/schema.sql
```

For an existing Glyph database, apply the icon-font migration:

```bash
mysql -u glyph -p glyph < database/migrations/20260715_icon_fonts.sql
```

Install the small server-side font builder:

```bash
python -m pip install -r requirements-fonts.txt
```

Point your local web server at:

```txt
public/
```

## License

Glyph is released under the [MIT License](LICENSE).
