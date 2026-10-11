=== WebP Studio ===
Contributors: pablodelacruz
Tags: images, webp, performance, optimization, media
Requires at least: 5.0
Tested up to: 7.1
Requires PHP: 7.0
Stable tag: 2.1.4
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

WebP Studio converts uploaded JPEG, PNG, and GIF images to WebP so WordPress serves smaller images, with an opt-in step that removes the previous-format files once it can prove nothing still needs them.

== Description ==

This plugin converts JPEG, PNG, and GIF images to WebP and updates the attachment record so WordPress treats the attachment as if it had always been WebP.

Existing images are converted **in the background** through WP-Cron, in small batches, so converting a large library never blocks a page load or locks up the admin.

= How upload conversion works =

Conversion runs when the request finishes its media work, not while WordPress is still reading the uploaded file. WordPress creates intermediate sizes incrementally and then performs a final metadata write of its own; converting any earlier deletes the source file out from under that code and the resulting WebP metadata is overwritten. By waiting until the end of the request, the WebP record is the one that persists.

If conversion cannot complete, the original file and its thumbnails are left untouched. Nothing is removed until a working WebP file **and** its full set of thumbnails exist.

= How removing the original files works =

This is the part worth reading before enabling "Eliminar imagen original después de convertir".

WordPress stores image URLs **as literal text** inside `post_content`. An image pasted into a post keeps pointing at its original `.jpg` no matter what the attachment record says. So deleting that `.jpg` the moment it is converted produces a broken image in that post, and no amount of correct attachment metadata prevents it.

The plugin therefore **never deletes on the spot**. Removing an original takes four steps, and each one has to finish before the next begins:

1. **Convert.** The WebP and its thumbnails are written and the attachment record is updated. The original stays on disk and is recorded in `_wps_pending_purge`.
2. **Scan.** The plugin walks the content and records every upload path still referenced. URLs pointing at images hosted somewhere else are left alone; only paths that match a local attachment count.
3. **Rewrite.** Content is repointed to the WebP version. Serialized values, such as page-builder and custom-field data, are unpacked and repacked rather than string-replaced, because a plain replace silently corrupts them.
4. **Verify and remove.** Only now, and only with a complete and current index, are the unused originals deleted. Anything the index cannot vouch for is left in place and counted under "en espera".

If step 2 or 3 has not run, or the index is stale, the removal step reports why and removes nothing. An image that is converted but not yet removable is normal, not an error: it means the site still points at the old file somewhere.

The whole cycle is reversible up to the moment a file is removed. After that it is not: a WebP cannot be turned back into a JPEG.

== Features ==

* Automatic conversion of JPEG, PNG, and GIF on upload (when enabled)
* Thumbnail metadata is correct on every new upload
* Background conversion of existing images via WP-Cron, in small bounded batches
* A site visitor or admin page load never performs bulk conversion work
* Reference rewriting across posts, pages, revisions, custom fields and options, with a persistent queue
* Verified, deferred removal of previous-format files: nothing is deleted while content still points at it
* Serialized data is rewritten safely instead of being corrupted by a string replace
* Every change is recorded and individually reversible
* Animated GIFs are left alone rather than silently flattened to a single frame
* Settings page with quality control, truthful live statistics, and an orphan cleanup tool
* Progress survives an interrupted run and resumes automatically
* Optional support for rewriting post revisions, off by default

== Installation ==

1. Upload the `webp-studio` directory to `/wp-content/plugins/`
2. Activate the plugin through the 'Plugins' menu in WordPress
3. Go to Settings > WebP Studio to configure options
4. Conversion starts automatically on upload once enabled

== Frequently Asked Questions ==

= Does this plugin replace my original images? =

Only when "Eliminar imagen original después de convertir" is enabled (the default), and then never immediately. The original is kept until the plugin can prove no content references it, and it can be removed from the settings page once the rewriting pass has run. Disable the option and both files are kept forever, which is the safest setting on a site where images are pasted into posts.

= Why do converted images still show a pending count to remove? =

Because a converted image whose `.jpg` is still referenced by a post cannot be removed yet. Run the scan and the rewrite from the settings page, then use "Verificar y borrar originales". The counter drops as files are actually removed.

= Do I have to rewrite post content at all? =

No, and on many sites you should not. If you keep the originals, nothing breaks and no rewriting is necessary. Rewriting is only required when you want the disk space back.

= Will rewriting my posts break my content? =

It repoints image URLs and changes nothing else. URLs belonging to images hosted elsewhere, such as agency photos or a CDN, are detected against the local attachment record and left untouched. Every change is written to a history table first and can be reverted one entry at a time.

= Does converting the existing library freeze the site? =

No. Conversion runs in a separate background request via WP-Cron, a few images at a time. You can close the settings page and keep working; it picks up where it left off if it is interrupted. If your site has very little traffic, WP-Cron may fire less often, so the settings page also nudges the queue along while it is open.

= Will this work with existing images? =

Yes. Tick "Convertir imágenes existentes" and save. Progress and pending count are shown live on the settings page.

= Does the plugin support animated GIFs? =

It skips animated ones, and says so in the activity log. No editor reads past frame 1 of an animated GIF, so converting one would silently discard the animation. Static GIFs and palette PNGs are converted normally.

= What happens to images that fail to convert? =

The original file, its thumbnails, and its metadata are all preserved, and the image is counted as skipped rather than converted.

= Where can I see what the plugin did? =

Every conversion and every skipped image is recorded in the activity log on the settings page, with the reason for each skip. The log polls live while a background run is in progress, and can be cleared at any time.

= Is deleting the originals reversible? =

Up to the point a file is removed, yes: every content change is recorded with its previous value and can be reverted one entry at a time from the settings page. Once a file has been removed from disk, it is gone: a WebP cannot be turned back into a JPEG. The plugin states this on the settings page and recommends a backup before running the removal step over a large library.

= Does the plugin handle my page builder or custom fields? =

Serialized values, which is what page builders and custom fields store, are unpacked, edited and repacked rather than string-replaced. A plain replace would change the byte lengths embedded in the serialization and make the value unreadable. Fields outside an explicit allowlist are not rewritten at all, because a site with millions of analytics rows in postmeta cannot be scanned safely by guessing.

= Why does my media library show thumbnails that are not listed in the attachment record? =

Older versions could leave intermediate sizes behind after an interrupted conversion. Use the "Limpiar miniaturas huérfanas" button on the settings page to remove files no attachment references.

== Screenshots ==

1. Plugin active status in Plugins screen
2. Settings page with live statistics
3. Example of WebP-only files in the uploads directory

== Changelog ==

= 2.1.4 =
* CodeQL explicito para JS y workflows, y releases disparados por tag en vez de por push a main. El tag se verifica contra el header del plugin antes de publicar.

= 2.1.2 =
* Corregido el borrado arbitrario de archivos fuera de uploads y la deserializacion de objetos en la cola.

= 2.1.1 =
* Corregida la entrada fantasma que inflaba el conteo de semillas fallidas.

= 2.1.0 =
* Security: originals are no longer deleted at conversion time. Removal is now verified against an index of what the content still references
* Added: a persistent queue and a reversible change history
* Added: a reference index built during the content scan, so removal never guesses
* Added: "Verificar y borrar originales", which refuses to delete when the index is incomplete
* Fixed: serialized values are rewritten safely instead of being corrupted by a string replace
* Fixed: the full-size original left behind by WordPress when it scales a large upload is now removed with the rest of the family
* Added: renaming the plugin to WebP Studio, with the directory, slug and text domain aligned

= 2.0.0 =
* Added: persistent work queue and change history
* Added: reference rewriting across posts, revisions, custom fields and options
* Fixed: the settings menu was never registered in a real HTTP request

= 1.3.0 =
* Fixed: thumbnail metadata was lost on every new upload
* Fixed: bulk conversion no longer blocks admin or front-end requests
* Fixed: orphaned thumbnails from the previous format are now removed
* Fixed: statistics use one query with consistent counts
* Fixed: attachment GUID is now actually updated (core silently ignored it)
* Added: background conversion via WP-Cron with resumable progress
* Added: orphan cleanup tool with a dry-run preview
* Added: animated GIFs are skipped instead of losing their frames
* Removed: the get_attached_file filter, which broke deletion and the image editor

= 1.2.0 =
* Automatic conversion on upload
* Settings page with quality control and bulk conversion

== Upgrade Notice ==

= 1.8.0 =
* Fixed: the chosen WebP quality was being ignored. WordPress resets the quality
  inside save() whenever the output type differs from the source type, so every
  JPEG/PNG/GIF to WebP conversion was encoded at the site default instead of the
  value selected here
* Changed: the quality preview uses an image the plugin generates itself, so it works
  on a fresh install and always shows the same comparison
* Fixed: the preview image never loaded, because it was written to a temporary
  directory while the URL pointed at uploads
* Changed: the preview compares against an equivalent JPEG rather than the lossless
  source, so the reported saving is meaningful
* Added: generated previews and the sample image are removed on uninstall

= 1.7.0 =
* Changed: the activity log toggle moved to its own card below the log, so saving the
  main settings form no longer touches it
* Changed: the activity log panel has a fixed height, so the page does not jump as
  entries arrive

= 1.6.0 =
* Added: WebP quality is now a slider with a live example rendered from an image in
  your own library, showing the resulting file size and percentage saved
* Fixed: the progress panel now updates on its own instead of requiring a page reload
* Added: progress is reported as a percentage of the whole library, with a live status
  indicator and a counter for images converted in the current run

= 1.5.0 =
* Added: activity log on the settings page recording every conversion and every
  skipped image with its reason, so nothing happens invisibly
* Added: the settings page states plainly that deleting originals is irreversible
  and recommends a backup before a bulk run
* Added: skipped images are summarised at the end of a run, grouped by reason
* Added: negative size deltas are reported instead of hidden
* Added: two-step orphan cleanup, which always previews the files first
* Fixed: palette GIFs and PNGs now convert (GD silently produced 0-byte WebP files)
* Fixed: produced WebP files are validated before the original is removed
* Fixed: conversion now goes through WP_Image_Editor, honouring site filters and Imagick

= 1.4.0 =
* Fixed: orphaned-thumbnail detection now handles the "-scaled" filename WordPress
  uses for large images, which previously marked live files as orphans
* Fixed: orphan cleanup now runs one query for the whole scan instead of one per file
* Added: activation and deactivation hooks that stop background work cleanly
* Added: admin notice when the uploads folder is not writable
* Added: uninstall.php that removes plugin options without touching image files
* Changed: plugin name and text domain now match the directory slug
* Hardened: AJAX requests use check_ajax_referer(), request input is sanitized

= 1.3.0 =
Fixes thumbnail metadata on upload and moves bulk conversion off the request path.

== Copyright ==

WebP Studio 2.0.0
Copyright (c) 2024 Assistant

This program is free software; you can redistribute it and/or modify
it under the terms of the GNU General Public License, version 2, as
published by the Free Software Foundation.