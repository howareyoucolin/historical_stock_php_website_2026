<section id="tab-summary" class="tab-panel is-active" role="tabpanel">
  <article class="reportDoc">
    <header class="reportDocHeader">
      <span class="reportKicker">Simulation Report</span>
      <h1><?= h(section_value($strategy, 'name', 'Unnamed strategy')) ?></h1>
      <p class="reportLead"><?= h(section_value($takeaways, 'summary', 'No written assessment is available for this report yet.')) ?></p>
      <p class="reportMuted">
        Created at <?= h($row['created_at']) ?>
        <?php if (!empty($row['updated_by_name'])): ?>
          / Updated by <?= h($row['updated_by_name']) ?>
        <?php endif; ?>
      </p>
    </header>

    <section class="reportSection">
      <h2>Thesis</h2>
      <p class="reportBody"><?= h(section_value($thesis, 'summary', 'No forward-looking thesis was provided.')) ?></p>
    </section>

    <section class="reportSection">
      <h2>Goal</h2>
      <p class="reportBodyStrong"><?= h(section_value($objective, 'title', 'Unspecified objective')) ?></p>
      <p class="reportMuted">Primary metric: <?= h(section_value($objective, 'primaryMetric', '—')) ?></p>
    </section>

    <section class="reportSection">
      <h2>Period</h2>
      <dl class="reportFacts">
        <div><dt>Simulation range</dt><dd><?= h($periodLabel) ?></dd></div>
        <div><dt>Starting value</dt><dd><?= h(isset($simulation['startingValue']) ? format_money_value($simulation['startingValue']) : '—') ?></dd></div>
        <div><dt>Principal contributed</dt><dd><?= h(format_money_value($portfolioSummary['principal'] ?? 0)) ?></dd></div>
        <div><dt>Ending cash</dt><dd><?= h(format_money_value($simulation['endingCash'] ?? 0)) ?></dd></div>
      </dl>
    </section>

    <section class="reportSection">
      <h2>Strategy Rules</h2>
      <p class="reportBodyStrong"><?= h(section_value($strategy, 'name', 'Unnamed strategy')) ?> <span class="reportInlineMeta"><?= h(section_value($strategy, 'version')) ?></span></p>
      <?php $constraints = is_array($objective['constraints'] ?? null) ? $objective['constraints'] : []; ?>
      <?php if ($constraints === []): ?>
        <p class="reportEmptyLine">—</p>
      <?php else: ?>
        <ul class="reportBullets">
          <?php foreach ($constraints as $item): ?>
            <li><?= h((string) $item) ?></li>
          <?php endforeach; ?>
        </ul>
      <?php endif; ?>
    </section>

    <section class="reportSection">
      <h2>Result</h2>
      <dl class="reportSummaryList">
        <div class="reportSummaryRow"><dt>Ending value</dt><dd><strong><?= h(format_money_value($portfolioSummary['currentTotal'] ?? 0)) ?></strong><span>Portfolio value at the final available date</span></dd></div>
        <div class="reportSummaryRow"><dt>Total gain/loss</dt><dd><strong class="<?= h(metric_value_class('gain', $portfolioSummary['totalGainLoss'] ?? 0)) ?>"><?= h(format_signed_money_value(isset($portfolioSummary['totalGainLoss']) ? (float) $portfolioSummary['totalGainLoss'] : null)) ?></strong><span><?= h(isset($portfolioSummary['totalReturnPct']) ? format_signed_percent_value((float) $portfolioSummary['totalReturnPct']) : '—') ?></span></dd></div>
        <div class="reportSummaryRow"><dt>Avg yearly gain</dt><dd><strong class="<?= h(metric_value_class('return', $portfolioSummary['annualizedReturnPct'] ?? 0)) ?>"><?= h(isset($portfolioSummary['annualizedReturnPct']) ? format_signed_percent_value((float) $portfolioSummary['annualizedReturnPct']) : '—') ?></strong><span>Money-weighted annualized return using the recorded deposit schedule</span></dd></div>
        <div class="reportSummaryRow"><dt><?= h(section_value($benchmark, 'stockCode', 'SPY')) ?> yearly gain</dt><dd><strong class="<?= h(metric_value_class('return', $benchmark['annualizedReturnPct'] ?? 0)) ?>"><?= h(isset($benchmark['annualizedReturnPct']) ? format_signed_percent_value((float) $benchmark['annualizedReturnPct']) : '—') ?></strong><span><?php if (isset($benchmark['endingValue'])): ?><?= h(section_value($benchmark, 'stockCode', 'SPY')) ?> ending value: <?= h(format_money_value($benchmark['endingValue'])) ?><?php else: ?><?= h(section_value($benchmark, 'methodology', 'Benchmark data unavailable.')) ?><?php endif; ?></span></dd></div>
        <div class="reportSummaryRow"><dt>Max drawdown</dt><dd><strong class="<?= h(metric_value_class('drawdown', $portfolio['maxDrawdownPct'] ?? 0)) ?>"><?= h(isset($portfolio['maxDrawdownPct']) ? format_signed_percent_value((float) $portfolio['maxDrawdownPct']) : '—') ?></strong><span><?= h(format_display_value($portfolio['openPositionCount'] ?? 0)) ?> open positions at the end of the run</span></dd></div>
      </dl>
    </section>

    <section class="reportSection">
      <h2>Run Facts</h2>
      <dl class="reportFacts">
        <div><dt>Largest position</dt><dd><?= h(number_format((float) ($portfolio['largestPositionPct'] ?? 0), 2, '.', ',')) ?>%</dd></div>
        <div><dt>Cash position</dt><dd><?= h(format_money_value($simulation['endingCash'] ?? 0)) ?> (<?= h(number_format((float) ($portfolio['cashPct'] ?? 0), 2, '.', ',')) ?>%)</dd></div>
        <div><dt>Unique stocks traded</dt><dd><?= h(section_value($activity, 'uniqueStocksTraded', '0')) ?></dd></div>
      </dl>
    </section>

    <section class="reportSection">
      <h2>Tax Profile</h2>
      <dl class="reportFacts">
        <div><dt>Unrealized gain exposure</dt><dd><?= h(format_signed_money_value(isset($portfolioSummary['unrealizedGainLoss']) ? (float) $portfolioSummary['unrealizedGainLoss'] : null)) ?></dd></div>
        <div><dt>Long-term tax</dt><dd><?= h(format_money_value($taxes['longTermTax'] ?? 0)) ?></dd></div>
        <div><dt>Short-term tax</dt><dd><?= h(format_money_value($taxes['shortTermTax'] ?? 0)) ?></dd></div>
        <div><dt>Dividend tax</dt><dd><?= h(format_money_value($taxes['dividendTax'] ?? 0)) ?></dd></div>
        <div><dt>Total estimated tax</dt><dd><?= h(format_money_value($taxes['estimatedTax'] ?? 0)) ?></dd></div>
      </dl>
    </section>

    <section class="reportSection">
      <h2>Strategy Check</h2>
      <?php if ($factualFindings === []): ?>
        <p class="reportEmptyLine">—</p>
      <?php else: ?>
        <ul class="reportBullets">
          <?php foreach ($factualFindings as $item): ?>
            <li><?= h(scored_item_text($item)) ?></li>
          <?php endforeach; ?>
        </ul>
      <?php endif; ?>
    </section>

    <?php if (trim(section_value($report, 'note', '')) !== ''): ?>
      <section class="reportSection">
        <h2>Note</h2>
        <p class="reportBody"><?= h(section_value($report, 'note')) ?></p>
      </section>
    <?php endif; ?>

    <section class="reportSection">
      <h2>Positions At Report Date</h2>
      <?php $positionRows = is_array($positions['rows'] ?? null) ? $positions['rows'] : []; ?>
      <?php if ($positionRows === []): ?>
        <p class="reportEmptyLine">—</p>
      <?php else: ?>
        <div class="reportTableScroll">
          <table class="reportTable">
            <thead>
              <tr>
                <th class="alignLeft" scope="col">Symbol</th>
                <th scope="col">Quantity</th>
                <th scope="col">Last Price</th>
                <th scope="col">Market Value</th>
                <th scope="col">Unit Cost</th>
                <th scope="col">Total Cost</th>
                <th scope="col">$ Gain/Loss</th>
                <th scope="col">% Gain/Loss</th>
                <th scope="col">% of Group</th>
              </tr>
            </thead>
            <tbody>
              <?php foreach ($positionRows as $positionRow): ?>
                <tr>
                  <td class="alignLeft reportPositionName"><?= h(is_array($positionRow) ? ($positionRow['stockCode'] ?? '') : '') ?></td>
                  <td><?= h(format_display_value(is_array($positionRow) ? ($positionRow['quantity'] ?? '') : '')) ?></td>
                  <td><?= h(format_money_value(is_array($positionRow) ? ($positionRow['currentPrice'] ?? '') : '')) ?></td>
                  <td><?= h(format_money_value(is_array($positionRow) ? ($positionRow['totalValue'] ?? '') : '')) ?></td>
                  <td><?= h(format_money_value(is_array($positionRow) ? ($positionRow['averageCost'] ?? '') : '')) ?></td>
                  <td><?= h(format_money_value(is_array($positionRow) ? ($positionRow['totalCostBasis'] ?? '') : '')) ?></td>
                  <td class="<?= h(metric_value_class('gain', is_array($positionRow) ? ($positionRow['totalGainLoss'] ?? '') : '')) ?>"><?= h(format_signed_money_value(is_array($positionRow) && isset($positionRow['totalGainLoss']) ? (float) $positionRow['totalGainLoss'] : null)) ?></td>
                  <td class="<?= h(metric_value_class('gain', is_array($positionRow) ? ($positionRow['totalGainLoss'] ?? '') : '')) ?>"><?= h(format_signed_percent_value(is_array($positionRow) && isset($positionRow['percentGainLoss']) ? (float) $positionRow['percentGainLoss'] : null)) ?></td>
                  <td><?= h(is_array($positionRow) && isset($positionRow['percentOfGroup']) ? number_format((float) $positionRow['percentOfGroup'], 2, '.', ',') . '%' : '—') ?></td>
                </tr>
              <?php endforeach; ?>
            </tbody>
          </table>
        </div>
      <?php endif; ?>
      <p class="reportMuted" style="margin-top: 10px;">As of <?= h(section_value($positions, 'asOfDate', section_value($simulation, 'simEndDate'))) ?></p>
    </section>
  </article>
</section>
