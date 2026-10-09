# Real Folders (Piwigo 16.4 plugin)

Albums are real folders under `galleries/`.

| You do in Piwigo | On disk |
|---|---|
| Create an album | `mkdir galleries/<parent>/<slug>` |
| Upload a photo | file lands in its album's folder as `<slug-of-filename>.<ext>` (not `upload/Y/M/D/…`) |
| Rename an album | folder renamed to the new slug, photo paths updated |
| Move an album (drag in the album tree, or the parent picker) | folder moved, photo paths updated |
| Delete an album | folder moved to `galleries/.rf-trash/` |

Folder and file names are lowercase slugs (`Summer 2026` → `summer-2026`), which is what Piwigo's own Synchronize accepts, so Synchronize keeps working alongside the plugin and ignores the trash.

A photo added to several albums lives in the folder of the album it was uploaded to; the other albums just link to it. When that album is deleted, the photo moves to one of its other albums first (unless you picked "delete all photos").

## Install

1. Put the plugin in `plugins/realfolders/`. The folder must be named `realfolders`. From inside the container:
   ```sh
   cd /path/to/piwigo/plugins
   git clone https://github.com/longblonde/piwigo-realfolders.git realfolders
   chown -R www-data:www-data realfolders
   ```
   To update later: `cd realfolders && git pull`.
2. Admin → Plugins → Real Folders → Activate.
3. Open its settings page and check **Storage**:
   - **Album folders** must resolve to your tank dataset. If `galleries/` sits on the container's root disk, move it onto the bind mount and symlink it back, e.g.
     `mv /var/www/piwigo/galleries /mnt/tank/piwigo/galleries && ln -s /mnt/tank/piwigo/galleries /var/www/piwigo/galleries`
     (adjust paths to yours).
   - **Writable** must say Yes. In an unprivileged CT, `www-data` is uid 100033 on the Proxmox host, so the dataset needs to be writable by that uid.

Existing virtual albums get a folder the first time something is uploaded into them, or all at once with **Give all albums folders** (tick the box to also move their photos out of `upload/`).

## Trash

Settings page → Trash lists deleted albums with size, file count and where they were.

- **Restore**: back where it was (gallery root if the parent album is gone).
- **Restore to…**: pick any album and a new name (rescue / rename).
- **Delete forever** / **Empty trash**: removes the files.

Restoring recreates sub-albums and photos, and puts back titles, descriptions, authors, dates, privacy levels and tags. Album permissions, ratings, comments and favourites aren't kept.

## Not covered

- Deleting individual photos still deletes the file (Piwigo's normal behaviour).
- Moving a photo between albums in the batch manager only changes links; the file stays in its upload album's folder.
- Uninstalling leaves folders and the trash as they are; albums stay physical.

## License

GPL-2.0, same as Piwigo.
