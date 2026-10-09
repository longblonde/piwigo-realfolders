<?php
defined('PHPWG_ROOT_PATH') or die('Hacking attempt!');

/*
 * Real Folders - core
 *
 * Model
 * -----
 * Every album the plugin manages is a Piwigo "physical" album: categories.dir
 * holds its folder name and site_id = 1 (the local galleries/ site), exactly
 * the state Piwigo's own Synchronize produces. Photos uploaded into an album
 * are stored in that folder (images.path = ./galleries/a/b/file.jpg,
 * images.storage_category_id = album). The album a photo was uploaded to owns
 * the file; other albums it is added to only link to it.
 *
 * RF_ALBUMS_TABLE remembers where each album's folder was last seen on disk
 * and what it was called, so after Piwigo renames or moves an album we can
 * compare and move the folder to match ("reconcile").
 *
 * Paths
 * -----
 * "rel" paths are relative to the galleries root, without leading or
 * trailing slash: "family/summer-2026". The root itself is "".
 */

// ---------------------------------------------------------------------------
// small helpers
// ---------------------------------------------------------------------------

function rf_starts_with($haystack, $needle)
{
  return $needle === '' or strncmp($haystack, $needle, strlen($needle)) === 0;
}

function rf_esc($s)
{
  return pwg_db_real_escape_string((string)$s);
}

function rf_log($message, $level='info')
{
  pwg_query('
INSERT INTO '.RF_LOG_TABLE.' (occured_on, level, message)
  VALUES (NOW(), \''.rf_esc($level).'\', \''.rf_esc($message).'\')
;');

  // keep the log short
  $rows = query2array('SELECT id FROM '.RF_LOG_TABLE.' ORDER BY id DESC LIMIT 200,1;', null, 'id');
  if (count($rows) > 0)
  {
    pwg_query('DELETE FROM '.RF_LOG_TABLE.' WHERE id <= '.intval($rows[0]).';');
  }

  if ('error' == $level)
  {
    global $logger;
    if (isset($logger))
    {
      $logger->error('[realfolders] '.$message);
    }
  }
}

function rf_admin_includes()
{
  include_once(PHPWG_ROOT_PATH.'admin/include/functions.php');
  include_once(PHPWG_ROOT_PATH.'admin/include/functions_upload.inc.php');
  include_once(PHPWG_ROOT_PATH.'admin/include/functions_metadata.php');
}

/** "./galleries/" (with trailing slash), as stored in the sites table */
function rf_galleries_url()
{
  static $url = null;
  if (null === $url)
  {
    $rows = query2array('SELECT galleries_url FROM '.SITES_TABLE.' WHERE id = 1;', null, 'galleries_url');
    $url = count($rows) ? $rows[0] : './galleries/';
    $url = rtrim($url, '/').'/';
  }
  return $url;
}

/** filesystem path of the galleries root, no trailing slash */
function rf_root_abs()
{
  $url = preg_replace('#^\./#', '', rf_galleries_url());
  return rtrim(PHPWG_ROOT_PATH.$url, '/');
}

function rf_abs($rel)
{
  return '' === $rel ? rf_root_abs() : rf_root_abs().'/'.$rel;
}

function rf_join($a, $b)
{
  return '' === $a ? $b : $a.'/'.$b;
}

/** value stored in images.path for a file in an album folder */
function rf_db_path($rel, $file)
{
  return rf_galleries_url().rf_join($rel, $file);
}

function rf_trash_abs()
{
  return rf_root_abs().'/'.RF_TRASH_DIR;
}

/** true when $path resolves to somewhere strictly inside $root */
function rf_inside($path, $root)
{
  $rp = realpath($path);
  $rr = realpath($root);
  if (false === $rp or false === $rr)
  {
    return false;
  }
  return rf_starts_with($rp, rtrim($rr, '/').'/');
}

// ---------------------------------------------------------------------------
// naming
// ---------------------------------------------------------------------------

function rf_reserved_names()
{
  return array(
    'pwg_representative', 'pwg_format', 'pwg_high', 'thumbnail',
    RF_TRASH_DIR, RF_LOCK_FILE,
    );
}

/**
 * Album or file name -> safe folder/file name: lowercase ASCII letters,
 * digits, dot, dash, underscore. That is also what Piwigo's Synchronize
 * accepts by default ($conf['sync_chars_regex']).
 */
function rf_slug($name, $fallback='album')
{
  $s = html_entity_decode((string)$name, ENT_QUOTES, 'UTF-8');
  $s = preg_replace('#\[/?lang[^\]]*\]#i', ' ', $s);  // multi-language markup
  $s = strip_tags($s);

  if (function_exists('transliterator_transliterate'))
  {
    $t = transliterator_transliterate('Any-Latin; Latin-ASCII', $s);
    if (false !== $t)
    {
      $s = $t;
    }
  }
  elseif (function_exists('iconv'))
  {
    $t = @iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $s);
    if (false !== $t)
    {
      $s = $t;
    }
  }

  $s = strtolower($s);
  $s = preg_replace('/[^a-z0-9._-]+/', '-', $s);
  $s = preg_replace('/-{2,}/', '-', $s);
  $s = trim($s, '-._');
  if (strlen($s) > 80)
  {
    $s = trim(substr($s, 0, 80), '-._');
  }
  if ('' === $s)
  {
    $s = $fallback;
  }
  if (in_array($s, rf_reserved_names()))
  {
    $s.= '-1';
  }
  return $s;
}

/**
 * First free name in $dir_abs: "base", "base-2", "base-3"...
 * $ext (without dot) is appended for files. $keep is a name that may be
 * reused because it is the very thing being renamed.
 */
function rf_unique_name($dir_abs, $base, $ext='', $keep=null)
{
  $suffix = '' === $ext ? '' : '.'.$ext;
  for ($i = 1; $i < 10000; $i++)
  {
    $candidate = (1 == $i ? $base : $base.'-'.$i).$suffix;
    if ($candidate === $keep or !file_exists($dir_abs.'/'.$candidate))
    {
      return $candidate;
    }
  }
  return $base.'-'.uniqid().$suffix;
}

// ---------------------------------------------------------------------------
// locking: uploads arrive in parallel, two of them may want to create the
// same album folder at the same time
// ---------------------------------------------------------------------------

function rf_lock()
{
  global $rf_lock_handle, $rf_lock_depth;
  if (!empty($rf_lock_depth))
  {
    $rf_lock_depth++;
    return;
  }
  $rf_lock_depth = 1;
  $root = rf_root_abs();
  if (!is_dir($root))
  {
    @mkdir($root, 0777, true);
  }
  $rf_lock_handle = @fopen($root.'/'.RF_LOCK_FILE, 'c');
  if ($rf_lock_handle)
  {
    flock($rf_lock_handle, LOCK_EX);
  }
}

function rf_unlock()
{
  global $rf_lock_handle, $rf_lock_depth;
  if (empty($rf_lock_depth))
  {
    return;
  }
  $rf_lock_depth--;
  if (0 == $rf_lock_depth and $rf_lock_handle)
  {
    flock($rf_lock_handle, LOCK_UN);
    fclose($rf_lock_handle);
    $rf_lock_handle = null;
  }
}

// ---------------------------------------------------------------------------
// data access
// ---------------------------------------------------------------------------

function rf_load_categories()
{
  return query2array('
SELECT id, id_uppercat, uppercats, name, dir, site_id
  FROM '.CATEGORIES_TABLE.'
;', 'id');
}

function rf_load_map()
{
  return query2array('SELECT * FROM '.RF_ALBUMS_TABLE.';', 'cat_id');
}

function rf_map_save($cat_id, $parent_id, $path, $name)
{
  pwg_query('
REPLACE INTO '.RF_ALBUMS_TABLE.' (cat_id, parent_id, path, name)
  VALUES ('.intval($cat_id).', '.(empty($parent_id) ? 'NULL' : intval($parent_id)).', \''.rf_esc($path).'\', \''.rf_esc($name).'\')
;');
}

function rf_is_local_physical($cat)
{
  return !empty($cat['dir']) and (empty($cat['site_id']) or 1 == $cat['site_id']);
}

function rf_is_remote($cat)
{
  return !empty($cat['site_id']) and 1 != $cat['site_id'];
}

function rf_depth($cat)
{
  return substr_count($cat['uppercats'], ',');
}

// ---------------------------------------------------------------------------
// album <-> folder
// ---------------------------------------------------------------------------

/**
 * Make sure an album has a folder, turning it (and any virtual parents) into
 * physical albums when needed. Returns the album's rel path, or false.
 */
function rf_ensure_physical($cat_id)
{
  rf_lock();
  try
  {
    $cats = rf_load_categories();
    $map = rf_load_map();
    return rf_ensure_physical_in($cat_id, $cats, $map);
  }
  finally
  {
    rf_unlock();
  }
}

function rf_ensure_physical_in($cat_id, &$cats, &$map)
{
  if (!isset($cats[$cat_id]))
  {
    return false;
  }
  $cat = $cats[$cat_id];

  if (rf_is_remote($cat))
  {
    return false;
  }

  $parent_rel = '';
  if (!empty($cat['id_uppercat']))
  {
    $parent_rel = rf_ensure_physical_in($cat['id_uppercat'], $cats, $map);
    if (false === $parent_rel)
    {
      return false;
    }
  }
  $parent_abs = rf_abs($parent_rel);

  if (!empty($cat['dir']))
  {
    $rel = rf_join($parent_rel, $cat['dir']);
    if (!is_dir(rf_abs($rel)))
    {
      if (!@mkdir(rf_abs($rel), 0777, true))
      {
        rf_log('Could not create folder "'.$rel.'" for album #'.$cat_id.' ('.$cat['name'].').', 'error');
        return false;
      }
    }
    if (!isset($map[$cat_id]))
    {
      rf_map_save($cat_id, $cat['id_uppercat'], $rel, $cat['name']);
      $map[$cat_id] = array('cat_id'=>$cat_id, 'parent_id'=>$cat['id_uppercat'], 'path'=>$rel, 'name'=>$cat['name']);
    }
    return $rel;
  }

  // virtual album: give it a folder
  if (!is_dir($parent_abs) and !@mkdir($parent_abs, 0777, true))
  {
    rf_log('Could not create folder "'.$parent_rel.'".', 'error');
    return false;
  }
  $dir = rf_unique_name($parent_abs, rf_slug($cat['name']));
  $rel = rf_join($parent_rel, $dir);

  if (!@mkdir(rf_abs($rel), 0777))
  {
    rf_log('Could not create folder "'.$rel.'" for album #'.$cat_id.' ('.$cat['name'].'). Is galleries/ writable by the web server?', 'error');
    return false;
  }
  @chmod(rf_abs($rel), 0777 & ~umask());

  single_update(
    CATEGORIES_TABLE,
    array('dir' => rf_esc($dir), 'site_id' => 1),
    array('id' => $cat_id)
    );
  $cats[$cat_id]['dir'] = $dir;
  $cats[$cat_id]['site_id'] = 1;

  rf_map_save($cat_id, $cat['id_uppercat'], $rel, $cat['name']);
  $map[$cat_id] = array('cat_id'=>$cat_id, 'parent_id'=>$cat['id_uppercat'], 'path'=>$rel, 'name'=>$cat['name']);

  rf_log('Created folder "'.$rel.'" for album "'.$cat['name'].'".');
  return $rel;
}

/** event: create_virtual_category */
function rf_on_create_category($category)
{
  global $rf_suppress_create;
  if (!empty($rf_suppress_create) or empty($category['id']))
  {
    return;
  }
  rf_ensure_physical($category['id']);
}

/**
 * Bring folders in line with what the database says: albums that were renamed
 * get their folder renamed, albums that were moved get their folder moved,
 * and photo paths follow. Safe to call any time; it does nothing when
 * everything already matches.
 */
function rf_reconcile()
{
  rf_lock();
  try
  {
    rf_admin_includes();
    $cats = rf_load_categories();
    $map = rf_load_map();

    // forget albums that no longer exist (deleted by some path we did not see)
    foreach (array_diff(array_keys($map), array_keys($cats)) as $gone)
    {
      pwg_query('DELETE FROM '.RF_ALBUMS_TABLE.' WHERE cat_id = '.intval($gone).';');
      unset($map[$gone]);
    }

    $physical = array();
    foreach ($cats as $id => $cat)
    {
      if (rf_is_local_physical($cat))
      {
        $physical[$id] = rf_depth($cat);
      }
    }
    asort($physical);  // parents before children

    $expected = array();  // cat_id => rel path after this pass
    $changed = false;

    foreach (array_keys($physical) as $id)
    {
      $cat = $cats[$id];

      $parent_rel = '';
      if (!empty($cat['id_uppercat']))
      {
        $pid = $cat['id_uppercat'];
        if (isset($expected[$pid]))
        {
          $parent_rel = $expected[$pid];
        }
        else
        {
          // physical album under a virtual one (it was just moved there):
          // the parent needs a folder too
          $parent_rel = rf_ensure_physical_in($pid, $cats, $map);
          if (false === $parent_rel)
          {
            continue;
          }
          $expected[$pid] = $parent_rel;
        }
      }
      $parent_abs = rf_abs($parent_rel);

      if (!isset($map[$id]))
      {
        // a physical album we have not seen before: adopt it as it is
        $rel = rf_join($parent_rel, $cat['dir']);
        if (!is_dir(rf_abs($rel)))
        {
          @mkdir(rf_abs($rel), 0777, true);
        }
        rf_map_save($id, $cat['id_uppercat'], $rel, $cat['name']);
        $map[$id] = array('cat_id'=>$id, 'parent_id'=>$cat['id_uppercat'], 'path'=>$rel, 'name'=>$cat['name']);
        $expected[$id] = $rel;
        continue;
      }

      $actual = $map[$id]['path'];
      $dir = $cat['dir'];

      // renamed or moved? the folder takes the name's slug, so a "-2" picked
      // to dodge a clash goes away once the clash does
      if ($map[$id]['name'] !== $cat['name'] or $map[$id]['parent_id'] != $cat['id_uppercat'])
      {
        $slug = rf_slug($cat['name']);
        if ($slug !== $dir)
        {
          $keep = (dirname('/'.$actual) === dirname('/'.rf_join($parent_rel, $dir))) ? basename($actual) : null;
          $dir = rf_unique_name($parent_abs, $slug, '', $keep);
        }
      }

      $target = rf_join($parent_rel, $dir);

      if ($target !== $actual)
      {
        if (!is_dir(rf_abs($actual)))
        {
          // folder vanished (removed by hand?): recreate it where it belongs
          @mkdir(rf_abs($target), 0777, true);
          rf_log('Folder "'.$actual.'" was missing; created "'.$target.'" for album "'.$cat['name'].'".', 'warning');
        }
        else
        {
          if (file_exists(rf_abs($target)))
          {
            $dir = rf_unique_name($parent_abs, $dir);
            $target = rf_join($parent_rel, $dir);
          }
          if (!is_dir($parent_abs))
          {
            @mkdir($parent_abs, 0777, true);
          }
          if (!@rename(rf_abs($actual), rf_abs($target)))
          {
            rf_log('Could not move folder "'.$actual.'" to "'.$target.'" (album "'.$cat['name'].'"). Photos still point at the old folder; will retry.', 'error');
            $expected[$id] = $actual;
            continue;
          }
          rf_move_photo_paths($actual, $target);
          rf_log('Moved folder "'.$actual.'" to "'.$target.'".');
        }

        // everything we remembered below the old path moved with it
        foreach ($map as $mid => $m)
        {
          if ($mid != $id and rf_starts_with($m['path'], $actual.'/'))
          {
            $map[$mid]['path'] = $target.substr($m['path'], strlen($actual));
            rf_map_save($mid, $m['parent_id'], $map[$mid]['path'], $m['name']);
          }
        }
        $changed = true;
      }

      if ($dir !== $cat['dir'])
      {
        single_update(CATEGORIES_TABLE, array('dir' => rf_esc($dir)), array('id' => $id));
        $cats[$id]['dir'] = $dir;
        $changed = true;
      }

      if ($target !== $actual or $map[$id]['name'] !== $cat['name'] or $map[$id]['parent_id'] != $cat['id_uppercat'])
      {
        rf_map_save($id, $cat['id_uppercat'], $target, $cat['name']);
        $map[$id] = array('cat_id'=>$id, 'parent_id'=>$cat['id_uppercat'], 'path'=>$target, 'name'=>$cat['name']);
      }

      $expected[$id] = $target;
    }

    if ($changed)
    {
      invalidate_user_cache();
    }
  }
  finally
  {
    rf_unlock();
  }
}

/** after a folder moved from $old_rel to $new_rel, fix images.path below it */
function rf_move_photo_paths($old_rel, $new_rel)
{
  $old_prefix = rf_galleries_url().$old_rel.'/';
  $new_prefix = rf_galleries_url().$new_rel.'/';

  $images = query2array('
SELECT id, path, representative_ext
  FROM '.IMAGES_TABLE.'
  WHERE LEFT(path, '.strlen($old_prefix).') = \''.rf_esc($old_prefix).'\'
;');
  if (count($images) == 0)
  {
    return;
  }

  pwg_query('
UPDATE '.IMAGES_TABLE.'
  SET path = CONCAT(\''.rf_esc($new_prefix).'\', SUBSTRING(path, '.(strlen($old_prefix) + 1).'))
  WHERE LEFT(path, '.strlen($old_prefix).') = \''.rf_esc($old_prefix).'\'
;');

  // cached thumbnails are keyed by path; the old ones are now orphans
  foreach ($images as $image)
  {
    delete_element_derivatives($image);
  }
  rf_remove_derivative_dir($old_rel);
}

/** remove the (now empty or orphaned) thumbnail cache folder of an album */
function rf_remove_derivative_dir($rel)
{
  $base = PHPWG_ROOT_PATH.PWG_DERIVATIVE_DIR;
  $dir = $base.preg_replace('#^\./#', '', rf_galleries_url()).$rel;
  if (is_dir($dir) and rf_inside($dir, $base))
  {
    rf_rmtree($dir);
  }
}

function rf_rmtree($dir)
{
  if (is_link($dir) or is_file($dir))
  {
    return @unlink($dir);
  }
  if (!is_dir($dir))
  {
    return true;
  }
  $ok = true;
  foreach (scandir($dir) as $node)
  {
    if ('.' == $node or '..' == $node)
    {
      continue;
    }
    $ok = rf_rmtree($dir.'/'.$node) && $ok;
  }
  return @rmdir($dir) && $ok;
}

// ---------------------------------------------------------------------------
// uploads
// ---------------------------------------------------------------------------

/** event: loc_end_add_uploaded_file */
function rf_on_uploaded_file($image_infos)
{
  if (empty($image_infos['id']))
  {
    return;
  }
  rf_admin_includes();

  $cat_id = rf_upload_target_category($image_infos['id']);
  if (empty($cat_id))
  {
    return;  // uploaded without an album: leave it in upload/
  }
  rf_move_photo_into_album($image_infos['id'], $cat_id);
}

/** which album an upload was meant for: the request first, then the database */
function rf_upload_target_category($image_id)
{
  $candidates = array();
  if (isset($_REQUEST['category']))
  {
    $raw = $_REQUEST['category'];
    if (!is_array($raw))
    {
      $raw = preg_split('/[\s,;\|]/', (string)$raw, -1, PREG_SPLIT_NO_EMPTY);
    }
    foreach ($raw as $c)
    {
      if (intval($c) > 0)
      {
        $candidates[] = intval($c);
      }
    }
  }

  if (count($candidates) > 0)
  {
    $found = query2array('SELECT id FROM '.CATEGORIES_TABLE.' WHERE id IN ('.implode(',', $candidates).');', null, 'id');
    foreach ($candidates as $c)
    {
      if (in_array($c, $found))
      {
        return $c;
      }
    }
  }

  $linked = query2array('
SELECT category_id FROM '.IMAGE_CATEGORY_TABLE.' WHERE image_id = '.intval($image_id).'
UNION
SELECT category_id FROM '.LOUNGE_TABLE.' WHERE image_id = '.intval($image_id).'
ORDER BY category_id
;', null, 'category_id');

  return count($linked) ? $linked[0] : null;
}

/**
 * Move one photo's file into an album's folder and make that album its
 * storage album. Used for uploads and when re-homing photos.
 * $force: also move photos that already live in another album folder.
 */
function rf_move_photo_into_album($image_id, $cat_id, $force=false)
{
  global $conf;

  $rows = query2array('
SELECT id, path, file, storage_category_id, representative_ext
  FROM '.IMAGES_TABLE.'
  WHERE id = '.intval($image_id).'
;');
  if (count($rows) == 0)
  {
    return false;
  }
  $image = $rows[0];

  if (!$force)
  {
    if (!empty($image['storage_category_id']))
    {
      return false;  // already lives in a folder (e.g. a file replacement)
    }
    $upload_prefix = $conf['upload_dir'].'/';
    $path = preg_replace('#^\./#', '', $image['path']);
    if (!rf_starts_with($path, preg_replace('#^\./#', '', $upload_prefix)))
    {
      return false;  // not something Piwigo put in upload/
    }
  }
  elseif ($image['storage_category_id'] == $cat_id)
  {
    return true;
  }

  rf_lock();
  try
  {
    $rel = rf_ensure_physical($cat_id);
    if (false === $rel)
    {
      return false;
    }
    $dir_abs = rf_abs($rel);

    $src = PHPWG_ROOT_PATH.preg_replace('#^\./#', '', $image['path']);
    if (!is_file($src))
    {
      rf_log('Photo #'.$image_id.': file "'.$image['path'].'" not found, left as is.', 'error');
      return false;
    }

    $ext = strtolower(get_extension($image['path']));
    $original = html_entity_decode($image['file'], ENT_QUOTES, 'UTF-8');
    $base = rf_slug(get_filename_wo_extension($original), 'photo');
    $file = rf_unique_name($dir_abs, $base, $ext);
    $dst = $dir_abs.'/'.$file;

    // thumbnails are keyed by path; drop the ones made for the old path
    delete_element_derivatives($image);

    if (!@rename($src, $dst))
    {
      rf_log('Photo #'.$image_id.': could not move "'.$image['path'].'" into "'.$rel.'".', 'error');
      return false;
    }

    // video/PDF/etc. posters live in pwg_representative/ next to the file
    if (!empty($image['representative_ext']))
    {
      $rep_src = original_to_representative($src, $image['representative_ext']);
      $rep_dst = original_to_representative($dst, $image['representative_ext']);
      if (is_file($rep_src))
      {
        @mkdir(dirname($rep_dst), 0777, true);
        @rename($rep_src, $rep_dst);
        rf_remove_empty_dirs(dirname($rep_src));
      }
    }

    // extra formats (pwg_format/) follow too
    $formats = query2array('SELECT ext FROM '.IMAGE_FORMAT_TABLE.' WHERE image_id = '.intval($image_id).';', null, 'ext');
    foreach ($formats as $format_ext)
    {
      $f_src = original_to_format($src, $format_ext);
      $f_dst = original_to_format($dst, $format_ext);
      if (is_file($f_src))
      {
        @mkdir(dirname($f_dst), 0777, true);
        @rename($f_src, $f_dst);
        rf_remove_empty_dirs(dirname($f_src));
      }
    }

    single_update(
      IMAGES_TABLE,
      array(
        'path' => rf_esc(rf_db_path($rel, $file)),
        'file' => rf_esc($file),
        'storage_category_id' => intval($cat_id),
        ),
      array('id' => $image_id)
      );

    // the storage album must also be one of the photo's albums
    pwg_query('
INSERT IGNORE INTO '.IMAGE_CATEGORY_TABLE.' (image_id, category_id)
  SELECT '.intval($image_id).', '.intval($cat_id).'
  FROM DUAL
  WHERE NOT EXISTS (SELECT 1 FROM '.LOUNGE_TABLE.' WHERE image_id = '.intval($image_id).' AND category_id = '.intval($cat_id).')
;');

    rf_remove_empty_dirs(dirname($src));
    return true;
  }
  finally
  {
    rf_unlock();
  }
}

/** remove empty upload/Y/M/D folders left behind, never above upload/ */
function rf_remove_empty_dirs($dir)
{
  global $conf;
  $stops = array(
    realpath(PHPWG_ROOT_PATH.$conf['upload_dir']),
    realpath(rf_root_abs()),
    realpath(PHPWG_ROOT_PATH),
    );
  for ($i = 0; $i < 6; $i++)
  {
    $real = realpath($dir);
    if (false === $real or in_array($real, $stops, true))
    {
      return;
    }
    $content = @scandir($dir);
    if (false === $content or count($content) > 2 or !@rmdir($dir))
    {
      return;
    }
    $dir = dirname($dir);
  }
}

// ---------------------------------------------------------------------------
// web service wrappers
// ---------------------------------------------------------------------------

/** event: ws_add_methods (runs after Piwigo registered its own methods) */
function rf_ws_add_methods($arr)
{
  global $rf_ws_original;
  $service = &$arr[0];

  $wrappers = array(
    'pwg.categories.setInfo' => 'rf_ws_setInfo',
    'pwg.categories.move'    => 'rf_ws_move',
    'pwg.categories.delete'  => 'rf_ws_delete',
    );

  foreach ($wrappers as $method => $wrapper)
  {
    if (isset($service->_methods[$method]))
    {
      $rf_ws_original[$method] = $service->_methods[$method]['callback'];
      $service->_methods[$method]['callback'] = $wrapper;
    }
  }

  if (isset($service->_methods['pwg.categories.move']))
  {
    $service->_methods['pwg.categories.move']['description'].= '<br><i>Real Folders: physical albums can be moved too; their folders move with them.</i>';
  }
}

function rf_ws_call_original($method, $params, &$service)
{
  global $rf_ws_original;
  return call_user_func_array($rf_ws_original[$method], array($params, &$service));
}

function rf_ws_setInfo($params, &$service)
{
  $result = rf_ws_call_original('pwg.categories.setInfo', $params, $service);
  if (!($result instanceof PwgError))
  {
    rf_reconcile();
  }
  return $result;
}

/**
 * Piwigo refuses to move physical albums. We hide their dir from it for the
 * duration of the call, let it do its normal move (permissions, ranks, the
 * response the admin pages expect), then put dir back and move the folders.
 */
function rf_ws_move($params, &$service)
{
  $ids = $params['category_id'];
  if (!is_array($ids))
  {
    $ids = preg_split('/[\s,;\|]/', (string)$ids, -1, PREG_SPLIT_NO_EMPTY);
  }
  $ids = array_filter(array_map('intval', $ids));

  $masked = array();
  if (count($ids) > 0 and get_pwg_token() == $params['pwg_token'])
  {
    $masked = query2array('
SELECT id, dir
  FROM '.CATEGORIES_TABLE.'
  WHERE id IN ('.implode(',', $ids).')
    AND dir IS NOT NULL
    AND (site_id IS NULL OR site_id = 1)
;', 'id', 'dir');
  }

  rf_lock();
  try
  {
    if (count($masked) > 0)
    {
      pwg_query('UPDATE '.CATEGORIES_TABLE.' SET dir = NULL WHERE id IN ('.implode(',', array_keys($masked)).');');
    }
    try
    {
      $result = rf_ws_call_original('pwg.categories.move', $params, $service);
    }
    finally
    {
      foreach ($masked as $id => $dir)
      {
        single_update(CATEGORIES_TABLE, array('dir' => rf_esc($dir)), array('id' => $id));
      }
    }
    rf_reconcile();
  }
  finally
  {
    rf_unlock();
  }
  return $result;
}

/**
 * Before Piwigo deletes albums: remember names and photo details for the
 * trash, and give photos that are also in surviving albums a new home so
 * deleting this album does not take them away from the others.
 */
function rf_ws_delete($params, &$service)
{
  global $rf_pending_manifests;

  if (get_pwg_token() == $params['pwg_token'])
  {
    $ids = $params['category_id'];
    if (!is_array($ids))
    {
      $ids = preg_split('/[\s,;\|]/', (string)$ids, -1, PREG_SPLIT_NO_EMPTY);
    }
    $ids = array_filter(array_map('intval', $ids));
    if (count($ids) > 0)
    {
      include_once(PHPWG_ROOT_PATH.'include/functions_category.inc.php');
      rf_admin_includes();
      $all_ids = get_subcat_ids($ids);
      $mode = isset($params['photo_deletion_mode']) ? $params['photo_deletion_mode'] : 'delete_orphans';

      if ('force_delete' != $mode)
      {
        rf_rehome_photos($all_ids);
      }
      $rf_pending_manifests = rf_build_manifests($all_ids);
    }
  }

  return rf_ws_call_original('pwg.categories.delete', $params, $service);
}

/**
 * photo <-> album links, including ones still waiting in the "lounge"
 * (Piwigo batches new links there when many photos are being added)
 */
function rf_links_sql()
{
  return 'SELECT image_id, category_id FROM '.IMAGE_CATEGORY_TABLE.'
    UNION SELECT image_id, category_id FROM '.LOUNGE_TABLE;
}

/** photos stored in albums about to be deleted, but shown in other albums too */
function rf_rehome_photos($deleted_ids)
{
  $list = implode(',', array_map('intval', $deleted_ids));
  $rows = query2array('
SELECT i.id, MIN(ic.category_id) AS new_home
  FROM '.IMAGES_TABLE.' AS i
    JOIN ('.rf_links_sql().') AS ic ON ic.image_id = i.id
  WHERE i.storage_category_id IN ('.$list.')
    AND ic.category_id NOT IN ('.$list.')
  GROUP BY i.id
;');
  foreach ($rows as $row)
  {
    if (rf_move_photo_into_album($row['id'], $row['new_home'], true))
    {
      rf_log('Photo #'.$row['id'].' was also in album #'.$row['new_home'].', moved its file there before deleting its folder.');
    }
  }
}

/** snapshot of album names and photo details, keyed by top-level album id */
function rf_build_manifests($all_ids)
{
  $map = rf_load_map();
  $cats = query2array('
SELECT id, name, comment, visible, commentable
  FROM '.CATEGORIES_TABLE.'
  WHERE id IN ('.implode(',', array_map('intval', $all_ids)).')
;', 'id');

  $tops = rf_top_level($all_ids, $map);
  $manifests = array();

  foreach ($tops as $top)
  {
    $top_path = $map[$top]['path'];
    $m = array('albums' => array(), 'photos' => array());

    foreach ($all_ids as $id)
    {
      if (!isset($map[$id]) or !isset($cats[$id]))
      {
        continue;
      }
      $p = $map[$id]['path'];
      if ($p === $top_path or rf_starts_with($p, $top_path.'/'))
      {
        $m['albums'][substr($p, strlen($top_path))] = array(
          'name' => $cats[$id]['name'],
          'comment' => $cats[$id]['comment'],
          'visible' => $cats[$id]['visible'],
          'commentable' => $cats[$id]['commentable'],
          );
      }
    }

    $prefix = rf_galleries_url().$top_path.'/';
    $photos = query2array('
SELECT id, path, name, comment, author, date_creation, level, latitude, longitude
  FROM '.IMAGES_TABLE.'
  WHERE LEFT(path, '.strlen($prefix).') = \''.rf_esc($prefix).'\'
;', 'id');
    if (count($photos) > 0)
    {
      $tags = query2array('
SELECT image_id, tag_id FROM '.IMAGE_TAG_TABLE.'
  WHERE image_id IN ('.implode(',', array_keys($photos)).')
;');
      foreach ($tags as $t)
      {
        $photos[$t['image_id']]['tags'][] = intval($t['tag_id']);
      }
      foreach ($photos as $photo)
      {
        $key = substr($photo['path'], strlen($prefix));
        unset($photo['id'], $photo['path']);
        $m['photos'][$key] = $photo;
      }
    }

    $manifests[$top] = $m;
  }
  return $manifests;
}

/** the albums in $ids that are not inside another album of $ids */
function rf_top_level($ids, $map)
{
  $paths = array();
  foreach ($ids as $id)
  {
    if (isset($map[$id]))
    {
      $paths[$id] = $map[$id]['path'];
    }
  }
  $tops = array();
  foreach ($paths as $id => $p)
  {
    $inside = false;
    foreach ($paths as $other => $op)
    {
      if ($other != $id and rf_starts_with($p, $op.'/'))
      {
        $inside = true;
        break;
      }
    }
    if (!$inside)
    {
      $tops[] = $id;
    }
  }
  return $tops;
}

// ---------------------------------------------------------------------------
// delete -> trash
// ---------------------------------------------------------------------------

/** event: delete_categories (album rows are already gone, files are not) */
function rf_on_delete_categories($ids)
{
  global $rf_pending_manifests;

  rf_lock();
  try
  {
    $map = rf_load_map();
    $tops = rf_top_level($ids, $map);

    foreach ($tops as $top)
    {
      $rel = $map[$top]['path'];
      $abs = rf_abs($rel);
      if (!is_dir($abs))
      {
        continue;
      }

      $manifest = isset($rf_pending_manifests[$top]) ? $rf_pending_manifests[$top] : array('albums' => array(), 'photos' => array());
      if (!isset($manifest['albums']['']))
      {
        $manifest['albums'][''] = array('name' => $map[$top]['name']);
      }
      // names of sub-albums we know about, in case the snapshot missed them
      foreach ($ids as $id)
      {
        if (isset($map[$id]) and rf_starts_with($map[$id]['path'], $rel.'/'))
        {
          $sub = substr($map[$id]['path'], strlen($rel));
          if (!isset($manifest['albums'][$sub]))
          {
            $manifest['albums'][$sub] = array('name' => $map[$id]['name']);
          }
        }
      }

      rf_trash_folder($rel, $map[$top]['parent_id'], $map[$top]['name'], $manifest);
      rf_remove_derivative_dir($rel);
    }

    if (count($ids) > 0)
    {
      pwg_query('DELETE FROM '.RF_ALBUMS_TABLE.' WHERE cat_id IN ('.implode(',', array_map('intval', $ids)).');');
    }
  }
  finally
  {
    rf_unlock();
  }
  $rf_pending_manifests = array();
}

function rf_trash_folder($rel, $parent_id, $name, $manifest)
{
  $trash = rf_trash_abs();
  if (!is_dir($trash) and !@mkdir($trash, 0777, true))
  {
    rf_log('Could not create the trash folder; "'.$rel.'" was left in place.', 'error');
    return false;
  }

  // timestamp from the database so it matches the "deleted on" shown in the admin
  list($stamp) = pwg_db_fetch_row(pwg_query('SELECT DATE_FORMAT(NOW(), \'%Y%m%d-%H%i%s\');'));
  $folder = rf_unique_name($trash, $stamp.'__'.basename($rel));
  if (!@rename(rf_abs($rel), $trash.'/'.$folder))
  {
    rf_log('Could not move "'.$rel.'" to the trash; it was left in place.', 'error');
    return false;
  }

  pwg_query('
INSERT INTO '.RF_TRASH_TABLE.' (folder, orig_path, orig_parent_id, album_name, deleted_on, manifest)
  VALUES (
    \''.rf_esc($folder).'\',
    \''.rf_esc($rel).'\',
    '.(empty($parent_id) ? 'NULL' : intval($parent_id)).',
    \''.rf_esc($name).'\',
    NOW(),
    \''.rf_esc(json_encode($manifest)).'\'
  )
;');
  rf_log('Album "'.$name.'" deleted: folder "'.$rel.'" moved to the trash.');
  return true;
}

// ---------------------------------------------------------------------------
// trash management
// ---------------------------------------------------------------------------

/** trash entries: database records plus any folder found there without one */
function rf_trash_list()
{
  $trash = rf_trash_abs();
  $records = query2array('SELECT * FROM '.RF_TRASH_TABLE.' ORDER BY deleted_on DESC, id DESC;', 'folder');

  $entries = array();
  foreach ($records as $folder => $r)
  {
    $r['exists'] = is_dir($trash.'/'.$folder);
    $entries[] = $r;
  }

  if (is_dir($trash))
  {
    foreach (scandir($trash) as $node)
    {
      if ('.' == $node[0] or isset($records[$node]) or !is_dir($trash.'/'.$node))
      {
        continue;
      }
      $entries[] = array(
        'id' => null,
        'folder' => $node,
        'orig_path' => null,
        'orig_parent_id' => null,
        'album_name' => preg_replace('/^\d{8}-\d{6}__/', '', $node),
        'deleted_on' => date('Y-m-d H:i:s', filemtime($trash.'/'.$node)),
        'manifest' => null,
        'exists' => true,
        );
    }
  }

  foreach ($entries as &$e)
  {
    $e['files'] = 0;
    $e['albums'] = 0;
    $e['bytes'] = 0;
    if ($e['exists'])
    {
      rf_count_tree($trash.'/'.$e['folder'], $e);
    }
  }
  unset($e);
  return $entries;
}

function rf_count_tree($dir, &$stats)
{
  $stats['albums']++;
  foreach (scandir($dir) as $node)
  {
    if ('.' == $node or '..' == $node)
    {
      continue;
    }
    $path = $dir.'/'.$node;
    if (is_dir($path))
    {
      if (in_array($node, array('pwg_representative', 'pwg_format', 'pwg_high', 'thumbnail')))
      {
        continue;
      }
      rf_count_tree($path, $stats);
    }
    elseif (is_file($path))
    {
      $stats['files']++;
      $stats['bytes'] += filesize($path);
    }
  }
}

/** validated absolute path of a trash entry, or false */
function rf_trash_entry_abs($folder)
{
  if (!is_string($folder) or '' === $folder or false !== strpos($folder, '/') or '.' == $folder[0])
  {
    return false;
  }
  $abs = rf_trash_abs().'/'.$folder;
  if (!is_dir($abs) or !rf_inside($abs, rf_trash_abs()))
  {
    return false;
  }
  return $abs;
}

function rf_trash_record($folder)
{
  $rows = query2array('SELECT * FROM '.RF_TRASH_TABLE.' WHERE folder = \''.rf_esc($folder).'\';');
  return count($rows) ? $rows[0] : null;
}

function rf_trash_delete($folder)
{
  $abs = rf_trash_entry_abs($folder);
  if (false !== $abs and !rf_rmtree($abs))
  {
    rf_log('Could not fully delete "'.$folder.'" from the trash.', 'error');
    return false;
  }
  pwg_query('DELETE FROM '.RF_TRASH_TABLE.' WHERE folder = \''.rf_esc($folder).'\';');
  rf_log('Permanently deleted "'.$folder.'" from the trash.');
  return true;
}

/**
 * Put a trashed folder back as an album.
 * $parent_id: 'original', 0 for the gallery root, or an album id.
 * $name: album name to use (null = the name it had).
 * Returns the new album id, or an error string.
 */
function rf_trash_restore($folder, $parent_id='original', $name=null)
{
  rf_admin_includes();

  $src = rf_trash_entry_abs($folder);
  if (false === $src)
  {
    return 'That trash entry no longer exists.';
  }
  $record = rf_trash_record($folder);
  $manifest = array('albums' => array(), 'photos' => array());
  if ($record and !empty($record['manifest']))
  {
    $decoded = json_decode($record['manifest'], true);
    if (is_array($decoded))
    {
      $manifest = array_merge($manifest, $decoded);
    }
  }

  if ('original' === $parent_id)
  {
    $parent_id = 0;
    if ($record and !empty($record['orig_parent_id']))
    {
      $exists = query2array('SELECT id FROM '.CATEGORIES_TABLE.' WHERE id = '.intval($record['orig_parent_id']).';');
      if (count($exists) > 0)
      {
        $parent_id = intval($record['orig_parent_id']);
      }
    }
  }
  $parent_id = intval($parent_id);

  if (null === $name or '' === trim($name))
  {
    if (isset($manifest['albums']['']['name']))
    {
      $name = $manifest['albums']['']['name'];
    }
    elseif ($record)
    {
      $name = $record['album_name'];
    }
    else
    {
      $name = preg_replace('/^\d{8}-\d{6}__/', '', $folder);
    }
  }
  else
  {
    // a new name replaces whatever the snapshot said for the top album
    $manifest['albums']['']['name'] = $name;
  }

  rf_lock();
  try
  {
    $parent_rel = '';
    if ($parent_id > 0)
    {
      $parent_rel = rf_ensure_physical($parent_id);
      if (false === $parent_rel)
      {
        return 'Could not prepare the destination album\'s folder.';
      }
    }

    $dir = rf_unique_name(rf_abs($parent_rel), rf_slug($name));
    $rel = rf_join($parent_rel, $dir);
    if (!@rename($src, rf_abs($rel)))
    {
      return 'Could not move the folder out of the trash.';
    }

    $stats = array('albums' => 0, 'photos' => 0);
    $new_id = rf_import_folder($rel, $parent_id, $name, $manifest, '', $stats);

    pwg_query('DELETE FROM '.RF_TRASH_TABLE.' WHERE folder = \''.rf_esc($folder).'\';');
  }
  finally
  {
    rf_unlock();
  }

  update_category('all');
  invalidate_user_cache();

  rf_log(sprintf('Restored "%s" from the trash to "%s" (%d album(s), %d photo(s)).', $folder, $rel, $stats['albums'], $stats['photos']));
  return $new_id;
}

/**
 * Register a folder already sitting at $rel as an album (and its sub-folders
 * as sub-albums), adding the files in it as photos.
 */
function rf_import_folder($rel, $parent_id, $name, &$manifest, $mkey, &$stats)
{
  global $conf, $user, $rf_suppress_create;

  $album = isset($manifest['albums'][$mkey]) ? $manifest['albums'][$mkey] : array();

  $rf_suppress_create = true;
  $created = create_virtual_category($name, $parent_id > 0 ? $parent_id : null);
  $rf_suppress_create = false;
  if (!isset($created['id']))
  {
    rf_log('Could not create album "'.$name.'": '.(isset($created['error']) ? $created['error'] : 'unknown error'), 'error');
    return null;
  }
  $cat_id = $created['id'];
  $stats['albums']++;

  $update = array('dir' => rf_esc(basename($rel)), 'site_id' => 1);
  if (isset($album['comment']) and null !== $album['comment'])
  {
    $update['comment'] = rf_esc($album['comment']);
  }
  if (isset($album['commentable']))
  {
    $update['commentable'] = rf_esc($album['commentable']);
  }
  single_update(CATEGORIES_TABLE, $update, array('id' => $cat_id));
  rf_map_save($cat_id, $parent_id, $rel, $name);

  $abs = rf_abs($rel);
  $allowed = array_map('strtolower', $conf['file_ext']);
  $skip_dirs = array('pwg_representative', 'pwg_format', 'pwg_high', 'thumbnail');

  list($dbnow) = pwg_db_fetch_row(pwg_query('SELECT NOW();'));
  $nodes = scandir($abs);
  $image_ids = array();
  $photo_details = array();

  foreach ($nodes as $node)
  {
    $path = $abs.'/'.$node;
    if ('.' == $node[0] or !is_file($path))
    {
      continue;
    }
    $ext = strtolower(get_extension($node));
    if (!in_array($ext, $allowed))
    {
      continue;
    }

    $insert = array(
      'file' => rf_esc($node),
      'name' => rf_esc(get_name_from_file($node)),
      'date_available' => $dbnow,
      'path' => rf_esc(rf_db_path($rel, $node)),
      'filesize' => floor(filesize($path) / 1024),
      'md5sum' => md5_file($path),
      'storage_category_id' => $cat_id,
      'added_by' => $user['id'],
      );

    $size = @getimagesize($path);
    if (false !== $size)
    {
      $insert['width'] = $size[0];
      $insert['height'] = $size[1];
    }

    foreach (array('jpg', 'png', 'gif', 'webp') as $rep_ext)
    {
      if (is_file(original_to_representative($path, $rep_ext)))
      {
        $insert['representative_ext'] = $rep_ext;
        $rep_size = @getimagesize(original_to_representative($path, $rep_ext));
        if (false !== $rep_size and !isset($insert['width']))
        {
          $insert['width'] = $rep_size[0];
          $insert['height'] = $rep_size[1];
        }
        break;
      }
    }

    single_insert(IMAGES_TABLE, $insert);
    $image_id = pwg_db_insert_id(IMAGES_TABLE);
    $image_ids[] = $image_id;
    $stats['photos']++;

    $key = ltrim(rf_join(ltrim($mkey, '/'), $node), '/');
    if (isset($manifest['photos'][$key]))
    {
      $photo_details[$image_id] = $manifest['photos'][$key];
    }
  }

  if (count($image_ids) > 0)
  {
    associate_images_to_categories($image_ids, array($cat_id));
    if ($conf['use_exif'] and !function_exists('exif_read_data'))
    {
      $conf['use_exif'] = false;
    }
    sync_metadata($image_ids);

    // titles, descriptions, tags... the photos had before they were deleted
    foreach ($photo_details as $image_id => $d)
    {
      $upd = array();
      foreach (array('name', 'comment', 'author', 'date_creation', 'level', 'latitude', 'longitude') as $field)
      {
        if (array_key_exists($field, $d) and null !== $d[$field])
        {
          $upd[$field] = rf_esc($d[$field]);
        }
      }
      if (count($upd) > 0)
      {
        single_update(IMAGES_TABLE, $upd, array('id' => $image_id));
      }
      if (!empty($d['tags']))
      {
        pwg_query('
INSERT IGNORE INTO '.IMAGE_TAG_TABLE.' (image_id, tag_id)
  SELECT '.intval($image_id).', id FROM '.TAGS_TABLE.'
  WHERE id IN ('.implode(',', array_map('intval', $d['tags'])).')
;');
      }
    }
  }

  foreach ($nodes as $node)
  {
    $path = $abs.'/'.$node;
    if ('.' == $node[0] or !is_dir($path) or in_array($node, $skip_dirs))
    {
      continue;
    }
    $child_key = $mkey.'/'.$node;
    $child_name = isset($manifest['albums'][$child_key]['name']) ? $manifest['albums'][$child_key]['name'] : $node;
    rf_import_folder(rf_join($rel, $node), $cat_id, $child_name, $manifest, $child_key, $stats);
  }

  return $cat_id;
}

// ---------------------------------------------------------------------------
// existing virtual albums
// ---------------------------------------------------------------------------

/**
 * Give every virtual album a folder. With $move_photos, photos that were
 * uploaded into an album (still in upload/) move into that album's folder.
 */
function rf_convert_all($move_photos)
{
  rf_admin_includes();
  $result = array('albums' => 0, 'photos' => 0, 'failed' => 0);

  $virtual = query2array('
SELECT id FROM '.CATEGORIES_TABLE.'
  WHERE dir IS NULL
  ORDER BY global_rank
;', null, 'id');

  foreach ($virtual as $id)
  {
    if (false === rf_ensure_physical($id))
    {
      $result['failed']++;
    }
    else
    {
      $result['albums']++;
    }
  }

  if ($move_photos)
  {
    global $conf;
    $prefix = preg_replace('#^\./#', '', $conf['upload_dir']).'/';
    $rows = query2array('
SELECT i.id, MIN(ic.category_id) AS cat_id
  FROM '.IMAGES_TABLE.' AS i
    JOIN ('.rf_links_sql().') AS ic ON ic.image_id = i.id
  WHERE i.storage_category_id IS NULL
    AND (LEFT(i.path, '.strlen($prefix).') = \''.rf_esc($prefix).'\'
      OR LEFT(i.path, '.(strlen($prefix) + 2).') = \''.rf_esc('./'.$prefix).'\')
  GROUP BY i.id
;');
    foreach ($rows as $row)
    {
      if (rf_move_photo_into_album($row['id'], $row['cat_id']))
      {
        $result['photos']++;
      }
      else
      {
        $result['failed']++;
      }
    }
  }

  if ($result['albums'] or $result['photos'])
  {
    invalidate_user_cache();
    rf_log(sprintf('Converted %d album(s) to folders, moved %d photo(s) out of upload/.', $result['albums'], $result['photos']));
  }
  return $result;
}

// ---------------------------------------------------------------------------
// health checks for the settings page
// ---------------------------------------------------------------------------

function rf_status()
{
  global $conf;
  $root = rf_root_abs();
  $upload = PHPWG_ROOT_PATH.$conf['upload_dir'];

  $s = array(
    'galleries_url' => rf_galleries_url(),
    'galleries_real' => realpath($root),
    'galleries_exists' => is_dir($root),
    'galleries_writable' => false,
    'upload_real' => realpath($upload),
    'same_device' => null,
    'web_user' => function_exists('posix_geteuid') ? (posix_getpwuid(posix_geteuid())['name'] ?? posix_geteuid()).' (uid '.posix_geteuid().')' : get_current_user(),
    'free_space' => @disk_free_space($root),
    'virtual_albums' => 0,
    'physical_albums' => 0,
    'photos_in_upload' => 0,
    );

  if ($s['galleries_exists'])
  {
    $probe = $root.'/.rf-write-test-'.uniqid();
    if (@mkdir($probe))
    {
      $s['galleries_writable'] = true;
      @rmdir($probe);
    }
  }

  if (is_dir($root) and is_dir($upload))
  {
    $a = @stat($root);
    $b = @stat($upload);
    if ($a and $b)
    {
      $s['same_device'] = ($a['dev'] == $b['dev']);
    }
  }

  list($s['virtual_albums']) = pwg_db_fetch_row(pwg_query('SELECT COUNT(*) FROM '.CATEGORIES_TABLE.' WHERE dir IS NULL;'));
  list($s['physical_albums']) = pwg_db_fetch_row(pwg_query('SELECT COUNT(*) FROM '.CATEGORIES_TABLE.' WHERE dir IS NOT NULL;'));
  list($s['photos_in_upload']) = pwg_db_fetch_row(pwg_query('SELECT COUNT(*) FROM '.IMAGES_TABLE.' WHERE storage_category_id IS NULL;'));

  return $s;
}
