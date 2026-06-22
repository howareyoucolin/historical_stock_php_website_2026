<section id="tab-values" class="tab-panel" role="tabpanel">
  <div class="section-stack">
    <section class="card">
      <div class="file-meta"><?= h($row['values_log_path']) ?></div>
      <div class="mini-grid">
        <div class="mini-stat"><div class="mini-stat-label">Snapshots</div><div class="mini-stat-value"><?= h(format_display_value($valuesSummary['count'])) ?></div></div>
        <div class="mini-stat"><div class="mini-stat-label">First value</div><div class="mini-stat-value"><?= h(format_display_value($valuesSummary['first']['value'] ?? '-')) ?></div></div>
        <div class="mini-stat"><div class="mini-stat-label">Latest value</div><div class="mini-stat-value"><?= h(format_display_value($valuesSummary['last']['value'] ?? '-')) ?></div></div>
        <div class="mini-stat"><div class="mini-stat-label">Net change</div><div class="mini-stat-value <?= h(metric_value_class('gain', $valuesSummary['change'])) ?>"><?= h(format_display_value($valuesSummary['change'] ?? '-')) ?></div></div>
      </div>
    </section>

    <section class="card timeline-chart-card">
      <header class="summaryHeader">
        <div>
          <span class="summaryLabel">Total Value</span>
          <span class="summaryValue"><?= h(format_money_value($valuesSummary['last']['value'] ?? '-')) ?></span>
        </div>
        <div class="summaryChange <?= h($timelineToneClass) ?>">
          <span class="summaryChangeAmount">
            <?= h(format_signed_money_value(isset($valuesSummary['change']) ? (float) $valuesSummary['change'] : null)) ?>
            <?php if ($timelineChangePercent !== null): ?>
              (<?= h(format_signed_percent_value((float) $timelineChangePercent)) ?>)
            <?php endif; ?>
          </span>
          <span class="summaryRange"><?= h($valuesSummary['first']['date'] ?? '-') ?> -> <?= h($valuesSummary['last']['date'] ?? '-') ?></span>
        </div>
      </header>

      <?php if ($valueSnapshots === []): ?>
        <div class="timeline-empty">No timeline snapshots are available yet.</div>
      <?php else: ?>
        <div class="timeline-wrap">
          <div
            class="timeline-chart"
            data-timeline-chart
            data-trend="<?= h($timelineToneClass) ?>"
            aria-label="Portfolio value history from <?= h(format_timeline_date($valuesSummary['first']['date'] ?? null)) ?> to <?= h(format_timeline_date($valuesSummary['last']['date'] ?? null)) ?>"
            data-points="<?= h(json_encode($valueSnapshots, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP)) ?>"
          ></div>
          <div class="timeline-y-labels" data-timeline-y-labels></div>
          <div class="timeline-x-labels" data-timeline-x-labels></div>
          <div class="chartTooltip" data-timeline-tooltip>
            <div class="chartTooltipDate"></div>
            <div class="chartTooltipValue"></div>
            <div class="chartTooltipChange"></div>
          </div>
        </div>
      <?php endif; ?>
    </section>
  </div>
</section>
