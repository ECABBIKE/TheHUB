# Page-Specific CSS

Denna katalog innehaller sid-specifik CSS som laddas villkorligt baserat pa vilken sida som visas.

## Hur det fungerar

1. CSS-filer namnges efter sidan: `event.css`, `rider.css`, `results.css`
2. `components/head.php` laddar automatiskt ratt fil baserat pa `$pageInfo['page']`
3. Inline CSS i PHP-filer kan gradvis flyttas hit

## Filnamnskonvention

| Sida | CSS-fil |
|------|---------|
| `/pages/event.php` | `event.css` |
| `/pages/rider.php` | `rider.css` |
| `/pages/results.php` | `results.css` |
| `/pages/ranking.php` | `ranking.css` |
| `/pages/calendar/*` | `calendar.css` |

## Migrationsprocess

1. Kopiera inline `<style>` fran PHP-filen till CSS-filen
2. Testa att sidan ser likadan ut
3. Kommentera ut inline CSS (behall som backup)
4. Testa igen
5. Ta bort kommenterad CSS nar allt fungerar

## Viktigt

- Anvand CSS-variabler fran tokens.css
- Folj CLAUDE-CSS.md for alla stilregler
- Testa pa mobil, tablet och desktop
