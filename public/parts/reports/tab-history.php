<section id="tab-history" class="tab-panel" role="tabpanel">
  <section class="histories">
    <div class="historiesToolbar">
      <span class="historiesToolbarLabel">Show types</span>
      <div class="historiesFilters" role="group" aria-label="Filter history by event type">
        <?php foreach ($historyTypes as $type): ?>
          <?php $isChecked = !in_array($type, ['DIVIDEND', 'INTEREST'], true); ?>
          <label class="historiesFilterOption">
            <input class="historiesFilterCheckbox" type="checkbox" <?= $isChecked ? 'checked' : '' ?> data-history-filter="<?= h($type) ?>">
            <span><?= h($type) ?></span>
          </label>
        <?php endforeach; ?>
      </div>
    </div>
    <div class="tableScroll">
      <?php if ($historySummary['recent'] === []): ?>
        <div class="historiesFilteredEmpty">No activity recorded yet. Buys, sells, dividends, deposits, and corporate actions will show up here.</div>
      <?php else: ?>
        <table class="historiesTable">
          <thead>
            <tr>
              <th class="alignLeft" scope="col">Date</th>
              <th class="alignLeft" scope="col">Action</th>
              <th class="alignLeft" scope="col">Symbol</th>
              <th scope="col">Qty</th>
              <th scope="col">Price</th>
              <th class="alignLeft" scope="col">Acquired</th>
              <th class="alignLeft" scope="col">Term</th>
              <th scope="col">Cash</th>
              <th class="alignLeft" scope="col">Note</th>
            </tr>
          </thead>
          <tbody>
            <?php foreach ($historySummary['recent'] as $entry): ?>
              <tr data-history-type="<?= h($entry['type']) ?>">
                <td class="alignLeft"><?= h($entry['sim'] ?: '—') ?></td>
                <td class="alignLeft action"><?= h($entry['type']) ?></td>
                <td class="alignLeft symbol"><?= h($entry['stock'] !== '' ? $entry['stock'] : '—') ?></td>
                <td><?= h($entry['quantity'] !== '' ? format_display_value($entry['quantity']) : '—') ?></td>
                <td><?= h($entry['price'] !== '' ? format_display_value($entry['price']) : '—') ?></td>
                <td class="alignLeft"><?= h($entry['acquired'] !== '' ? $entry['acquired'] : '—') ?></td>
                <td class="alignLeft">
                  <?php if ($entry['term'] !== ''): ?>
                    <span class="termBadge <?= h(strtolower($entry['term'])) ?>"><?= h($entry['term']) ?></span>
                  <?php else: ?>
                    —
                  <?php endif; ?>
                </td>
                <td class="<?= h(metric_value_class('cash', $entry['cash'])) ?>"><?= h($entry['cash'] !== '' ? $entry['cash'] : '—') ?></td>
                <td class="alignLeft note"><?= h($entry['note'] !== '' ? $entry['note'] : '—') ?></td>
              </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      <?php endif; ?>
    </div>
  </section>
</section>
