# Asset Label - GLPI plugin

[![Release](https://github.com/Bakus/glpi-assetlabel/actions/workflows/release.yml/badge.svg)](https://github.com/Bakus/glpi-assetlabel/actions/workflows/release.yml)

Prints QR code inventory labels for GLPI assets on Brother QL labels, designed for the
**Brother QL-810W** (300 dpi). Labels are black & white / grayscale.

Supported label types:

- die-cut: **DK-11209** (62 × 29 mm), **DK-11204** (17 × 54 mm), **DK-11208** (38 × 90 mm),
  **DK-11202** (62 × 100 mm);
- continuous tapes: **DK-22205** (62 mm) and **DK-22210** (29 mm), with the label length set in the configuration (20-100 mm).
  Labels longer than the tape width are laid out in landscape.

- GLPI **12.0.x**, PHP **8.3 - 8.5** (extensions: gd with FreeType, zip)
- No database tables: settings are stored in GLPI's configuration

## Features

- **Label tab** on every enabled item type: preview + download as PDF or PNG.
- **Bulk action** "Print labels" on item lists: one multi-page PDF (one label per page)
  or PNG files (ZIP archive for several labels).
- **QR code content**: link to the item in GLPI, or a custom template using
  `{id} {name} {otherserial} {serial} {itemtype} {type} {entity} {url}`.
- **Label content** (each can be switched on/off): name, inventory number, item type,
  entity, an additional label with the same placeholders as the QR template
  (e.g. "Property of ACME" or "S/N: {serial}"), optional logo (converted to grayscale).
- **Item types** selectable in the configuration, including GLPI 12 custom assets.
- Label type selectable in the configuration, from the formats defined in `src/Format/`.
- Printer test page with the safe area frame, to check the margins.

Labels are rendered once as a bitmap at the printer's native resolution (732 × 343 px at
300 dpi); the PDF contains that bitmap on a 62 × 29 mm page. QR modules are whole pixels,
so edges stay sharp.

## Installation

1. Put the plugin in GLPI's `plugins/` (or `marketplace/`) directory as `assetlabel/`.
2. Install and enable it in *Setup > Plugins*, or:
   ```sh
   php bin/console plugin:install assetlabel -u <admin user>
   php bin/console plugin:activate assetlabel
   ```
3. Configure it via the gear icon in *Setup > Plugins*
   (requires the right to update the general configuration).

Make sure the GLPI URL is set in *Setup > General*, otherwise links in QR codes are incomplete.

## Permissions

There are no plugin-specific rights:

- printing a label requires read access to the item (entities are respected);
- the configuration page requires the right to update GLPI's general configuration.

## Printing on a Brother QL-810W

- Select the paper size of the loaded labels in the driver:
  - DK-11209: **62 mm × 29 mm**;
  - DK-11204: **17 mm × 54 mm**, DK-11208: **38 mm × 90 mm** and DK-11202: **62 mm × 100 mm**;
    these labels are laid out
    in landscape (long side horizontal), so print in landscape orientation;
  - DK-22205: **62 mm** and DK-22210: **29 mm** continuous tape, with the length
    set in the plugin configuration; print in landscape orientation when the label is longer
    than the tape width.
- Print the PDF at **100 % / actual size** - no "fit to page".
- Start with *Printer test* on the configuration page. Nothing should be cut off, and the
  gray frame should sit fully inside the label. The margins are defined in the format
  classes in `src/Format/`.

## Label types

The label type is chosen in the configuration (*Output > Label type*). The list is built
automatically from the classes in `src/Format/`: to add a type, add a file there with a
class implementing `GlpiPlugin\Assetlabel\Format\LabelFormat` (name, size, resolution,
margins), named like the file. Continuous tapes extend `ContinuousTape` (only the tape width
and name are needed) and get their length from the configuration.

The layout adapts to the size: the QR code takes at most the same share of the width as on DK-11209 (~39 %), text grows on
labels taller than DK-11209, and on small labels the text lines that do not fit are left
out, starting from the last one (additional label, then type / entity). When the QR modules would
be too small, the QR code falls back to the lowest error correction level (L).

## Translations

Source strings are in English (domain `assetlabel`); the Polish translation is in `locales/`.
After editing a translation, recompile it:

```sh
msgfmt locales/pl_PL.po -o locales/pl_PL.mo
```

## License

[0BSD](LICENSE) - provided "as is", without any warranty.
The bundled Noto Sans fonts are under the SIL Open Font License 1.1 (`fonts/OFL.txt`).
