{combine_css path="plugins/realfolders/template/admin.css"}
{footer_script}
jQuery(function($) {
  $('.rf-restore-toggle').on('click', function(e) {
    e.preventDefault();
    $(this).closest('.rf-entry').find('.rf-restore-form').slideToggle(150);
  });
  $('.rf-confirm').on('submit', function() {
    return confirm($(this).data('confirm'));
  });
});
{/footer_script}

<div class="titrePage"><h2>Real Folders</h2></div>

<div class="rf">

{* ---------------------------------------------------------------- status *}
<section class="rf-card">
  <h3>Storage</h3>
  <table class="rf-kv">
    <tr>
      <th>Album folders</th>
      <td>
        <code>{$RF_STATUS.galleries_url|escape}</code>
        {if $RF_STATUS.galleries_real} &rarr; <code>{$RF_STATUS.galleries_real|escape}</code>{/if}
      </td>
    </tr>
    <tr>
      <th>Writable</th>
      <td>
        {if !$RF_STATUS.galleries_exists}
          <span class="rf-bad">Folder does not exist.</span> Create it and make it writable by the web server.
        {elseif $RF_STATUS.galleries_writable}
          <span class="rf-ok">Yes</span>, as {$RF_STATUS.web_user|escape}
        {else}
          <span class="rf-bad">No</span> &mdash; the web server ({$RF_STATUS.web_user|escape}) cannot create folders here, so new albums will stay virtual.
        {/if}
      </td>
    </tr>
    <tr>
      <th>Upload folder</th>
      <td>
        <code>{$RF_STATUS.upload_real|default:'(missing)'|escape}</code>
        {if $RF_STATUS.same_device === false}
          <div class="rf-warn">On a different filesystem from the album folders. Uploads still work, but each one is copied instead of moved, and only the album folders are on that storage.</div>
        {/if}
      </td>
    </tr>
    <tr>
      <th>Free space</th>
      <td>{$RF_STATUS.free_space_h}</td>
    </tr>
    <tr>
      <th>Albums</th>
      <td>{$RF_STATUS.physical_albums} with folders, {$RF_STATUS.virtual_albums} virtual &middot; {$RF_STATUS.photos_in_upload} photo(s) not in an album folder</td>
    </tr>
  </table>

  <div class="rf-actions">
    <form method="post" action="{$RF_ACTION}" class="rf-inline">
      <input type="hidden" name="pwg_token" value="{$RF_TOKEN}">
      <input type="hidden" name="rf_action" value="recheck">
      <button type="submit" class="buttonLike">Re-check folders</button>
    </form>

    {if $RF_STATUS.virtual_albums > 0 or $RF_STATUS.photos_in_upload > 0}
    <form method="post" action="{$RF_ACTION}" class="rf-inline rf-confirm" data-confirm="Create folders for all virtual albums?">
      <input type="hidden" name="pwg_token" value="{$RF_TOKEN}">
      <input type="hidden" name="rf_action" value="convert">
      <button type="submit" class="buttonLike">Give all albums folders</button>
      <label class="rf-check"><input type="checkbox" name="move_photos" value="1"> also move their photos out of upload/</label>
    </form>
    {/if}
  </div>
</section>

{* ---------------------------------------------------------------- trash *}
<section class="rf-card">
  <div class="rf-card-head">
    <h3>Trash <span class="rf-count">{$RF_TRASH|@count}</span></h3>
    {if $RF_TRASH|@count > 0}
    <form method="post" action="{$RF_ACTION}" class="rf-inline rf-confirm" data-confirm="Permanently delete everything in the trash? This cannot be undone.">
      <input type="hidden" name="pwg_token" value="{$RF_TOKEN}">
      <input type="hidden" name="rf_action" value="empty">
      <button type="submit" class="buttonLike rf-danger">Empty trash</button>
    </form>
    {/if}
  </div>
  <p class="rf-muted">Deleted albums are moved to <code>{$RF_TRASH_DIR|escape}</code> instead of being erased. Restoring brings the folder back as an album with its photos, sub-albums, titles, descriptions and tags.</p>

  {if $RF_TRASH|@count == 0}
    <p class="rf-empty">The trash is empty.</p>
  {else}
  <ul class="rf-trash">
    {foreach from=$RF_TRASH item=t}
    <li class="rf-entry">
      <div class="rf-entry-main">
        <div class="rf-entry-title">
          <strong>{$t.NAME|escape}</strong>
          {if !$t.KNOWN}<span class="rf-tag">found on disk</span>{/if}
          {if !$t.EXISTS}<span class="rf-tag rf-tag-bad">folder missing</span>{/if}
        </div>
        <div class="rf-entry-meta">
          {if $t.ORIG_PATH}was <code>{$t.ORIG_PATH|escape}</code> &middot; {/if}
          deleted {$t.DELETED_ON}
          {if $t.EXISTS} &middot; {$t.FILES} file(s){if $t.ALBUMS > 1} in {$t.ALBUMS} albums{/if} &middot; {$t.SIZE}{/if}
        </div>
      </div>

      <div class="rf-entry-actions">
        {if $t.EXISTS}
        <form method="post" action="{$RF_ACTION}" class="rf-inline">
          <input type="hidden" name="pwg_token" value="{$RF_TOKEN}">
          <input type="hidden" name="rf_action" value="restore">
          <input type="hidden" name="folder" value="{$t.FOLDER|escape}">
          <input type="hidden" name="parent" value="original">
          <button type="submit" class="buttonLike" title="{if $t.ORIG_PARENT === false}Its parent album is gone, so it will go to the gallery root{elseif $t.ORIG_PARENT}Back into {$t.ORIG_PARENT|escape}{else}Back to the gallery root{/if}">Restore</button>
        </form>
        <a href="#" class="buttonLike rf-restore-toggle">Restore to&hellip;</a>
        {/if}
        <form method="post" action="{$RF_ACTION}" class="rf-inline rf-confirm" data-confirm="Permanently delete &quot;{$t.NAME|escape}&quot; and its files? This cannot be undone.">
          <input type="hidden" name="pwg_token" value="{$RF_TOKEN}">
          <input type="hidden" name="rf_action" value="delete">
          <input type="hidden" name="folder" value="{$t.FOLDER|escape}">
          <button type="submit" class="buttonLike rf-danger">Delete forever</button>
        </form>
      </div>

      {if $t.EXISTS}
      <form method="post" action="{$RF_ACTION}" class="rf-restore-form" style="display:none">
        <input type="hidden" name="pwg_token" value="{$RF_TOKEN}">
        <input type="hidden" name="rf_action" value="restore">
        <input type="hidden" name="folder" value="{$t.FOLDER|escape}">
        <label>
          <span>Into</span>
          <select name="parent">
            <option value="0">(gallery root)</option>
            {html_options options=$RF_ALBUMS}
          </select>
        </label>
        <label>
          <span>As</span>
          <input type="text" name="name" value="{$t.NAME|escape}" size="30">
        </label>
        <button type="submit" class="buttonLike">Restore here</button>
      </form>
      {/if}
    </li>
    {/foreach}
  </ul>
  {/if}
</section>

{* ---------------------------------------------------------------- log *}
<section class="rf-card">
  <h3>Recent activity</h3>
  {if $RF_LOG|@count == 0}
    <p class="rf-empty">Nothing yet.</p>
  {else}
  <table class="rf-log">
    {foreach from=$RF_LOG item=l}
    <tr class="rf-log-{$l.level|escape}">
      <td class="rf-log-when">{$l.when}</td>
      <td>{$l.message|escape}</td>
    </tr>
    {/foreach}
  </table>
  {/if}
</section>

</div>
