# Glyph

Minimal PHP web app for creating clean SVG icon sprites without npm, Composer, webpack, or a build step.

Glyph lets users upload SVG icons, sanitizes them on the server, normalizes color usage to `currentColor`, and returns a downloadable SVG sprite. Guests can build sprites in one browser session; signed-in users can save and manage sprite projects.

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

Point your local web server at:

```txt
public/
```

## License

Glyph is released under the [MIT License](LICENSE).
