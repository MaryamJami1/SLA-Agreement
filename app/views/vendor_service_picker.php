<?php
/**
 * Checkbox list of the services a vendor category offers (vendor_category_services), each with
 * how it is charged and its rate. Posts services[] (catalog ids), service_unit[id] and
 * service_rate[id]; see clean_catalog_picks().
 *
 * With $selectId, one group is rendered per category and app.js shows only the group of the
 * category chosen in that <select> (the others are hidden and disabled so they don't post).
 *
 * @param array    $catalog     from vendor_category_service_catalog()
 * @param int[]    $categoryIds the categories to render a group for
 * @param ?int     $selected    the category shown first
 * @param ?string  $selectId    id of the category <select> the list follows, or null for a fixed category
 * @param string[] $have        names the vendor already has (shown ticked and locked)
 * @param array    $post        the submitted form, to keep what was ticked and typed after an error
 */
function vendor_service_picker(array $catalog, array $categoryIds, ?int $selected, ?string $selectId, array $have = [], array $post = []): void
{
    $have = array_map('mb_strtolower', $have);
    $ticked = array_map('strval', is_array($post['services'] ?? null) ? $post['services'] : []);
    $units = is_array($post['service_unit'] ?? null) ? $post['service_unit'] : [];
    $rates = is_array($post['service_rate'] ?? null) ? $post['service_rate'] : [];
?>
  <div class="service-picker"<?= $selectId !== null ? ' data-follows="' . h($selectId) . '"' : '' ?>>
<?php if ($selectId !== null): ?>
    <p class="hint service-picker-empty"<?= $selected ? ' hidden' : '' ?>>Choose a category to see its services.</p>
<?php endif; ?>
<?php foreach ($categoryIds as $cid): $cid = (int) $cid; $shown = $cid === $selected; ?>
    <div class="service-group" data-category="<?= $cid ?>"<?= $shown ? '' : ' hidden' ?>>
<?php if (empty($catalog[$cid])): ?>
      <p class="hint">This category has no preset services. Add the vendor's services by name under “Add another service” on the vendor page.</p>
<?php else: ?>
      <p class="service-picker-tools">
        <button type="button" class="btn small" data-tick="all">Tick all</button>
        <button type="button" class="btn small" data-tick="none">Clear</button>
        <span class="hint">Leave a rate empty to set it later (it is saved as 0).</span>
      </p>
      <div class="service-list">
<?php foreach ($catalog[$cid] as $s):
          $sid = $s['id'];
          $owned = in_array(mb_strtolower($s['name']), $have, true);
          $off = $owned || !$shown;
          $unit = is_string($units[$sid] ?? null) ? $units[$sid] : 'fixed'; ?>
        <div class="check-row">
          <label><input type="checkbox" name="services[]" value="<?= $sid ?>"<?= $owned || in_array((string) $sid, $ticked, true) ? ' checked' : '' ?><?= $off ? ' disabled' : '' ?><?= $owned ? ' data-owned="1"' : '' ?>>
            <?= h($s['name']) ?><?php if ($owned): ?> <span class="hint">already added</span><?php endif; ?></label>
<?php if (!$owned): ?>
          <select name="service_unit[<?= $sid ?>]" aria-label="How “<?= h($s['name']) ?>” is charged"<?= $off ? ' disabled' : '' ?>>
<?php foreach (VENDOR_UNITS as $value => $label): ?>
            <option value="<?= h($value) ?>"<?= $unit === $value ? ' selected' : '' ?>><?= h($label) ?></option>
<?php endforeach; ?>
          </select>
          <input type="text" class="rate" name="service_rate[<?= $sid ?>]" inputmode="decimal" placeholder="Rate (Rs.)"
                 aria-label="Rate of “<?= h($s['name']) ?>”" value="<?= h(is_string($rates[$sid] ?? null) ? $rates[$sid] : '') ?>"<?= $off ? ' disabled' : '' ?>>
<?php endif; ?>
        </div>
<?php endforeach; ?>
      </div>
<?php endif; ?>
    </div>
<?php endforeach; ?>
  </div>
<?php
}
