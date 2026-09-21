<?php
$insightTotal = array_sum($insights['series']);
$insightMax = max(1, max($insights['series']));
?>
<div class="dashboard-insights">
    <section class="dashboard-surface" aria-labelledby="activity-heading">
        <div class="dashboard-section-heading"><div><span class="dashboard-kicker">Your activity</span><h2 id="activity-heading"><?php echo sanitize($insights['label']); ?></h2></div>
            <form method="get"><label class="visually-hidden" for="activity-period">Activity period</label><select id="activity-period" name="days" class="form-select form-select-sm"><option value="7" <?php echo $insights['days'] === 7 ? 'selected' : ''; ?>>Last 7 days</option><option value="30" <?php echo $insights['days'] === 30 ? 'selected' : ''; ?>>Last 30 days</option></select><button class="btn btn-outline-primary btn-sm" type="submit">Apply</button></form>
        </div>
        <div class="dashboard-activity-total"><strong><?php echo number_format($insightTotal); ?></strong><span>in the last <?php echo $insights['days']; ?> days</span></div>
        <div class="dashboard-chart" aria-hidden="true">
            <?php foreach ($insights['series'] as $day => $count): ?><div class="dashboard-chart__column" title="<?php echo sanitize(formatDate($day, 'd M') . ': ' . $count); ?>"><span style="height:<?php echo $count ? max(3, round($count / $insightMax * 100)) : 0; ?>%"></span></div><?php endforeach; ?>
        </div>
        <div class="dashboard-chart-labels"><span><?php echo formatDate(array_key_first($insights['series']), 'd M'); ?></span><span><?php echo $insightTotal ? 'Daily activity' : 'No activity in this period'; ?></span><span><?php echo formatDate(array_key_last($insights['series']), 'd M'); ?></span></div>
        <details class="dashboard-chart-data"><summary>View daily totals</summary><div class="table-responsive"><table class="table table-sm"><caption class="visually-hidden"><?php echo sanitize($insights['label']); ?> by day</caption><thead><tr><th scope="col">Date</th><th scope="col">Count</th></tr></thead><tbody><?php foreach ($insights['series'] as $day => $count): ?><tr><th scope="row"><?php echo formatDate($day, 'd M Y'); ?></th><td><?php echo $count; ?></td></tr><?php endforeach; ?></tbody></table></div></details>
    </section>
    <section class="dashboard-surface" aria-labelledby="priority-heading">
        <div class="dashboard-section-heading"><div><span class="dashboard-kicker">Focus next</span><h2 id="priority-heading"><?php echo sanitize($insights['queueTitle']); ?></h2></div><span class="dashboard-count"><?php echo number_format($insights['queueCount']); ?></span></div>
        <?php if ($insights['queue']): ?><div class="dashboard-priorities">
            <?php foreach ($insights['queue'] as $item):
                $itemUrl = $dashboardRole === 'student' ? '/student/assignment.php?assignment_id=' . (int) $item['id'] : ($dashboardRole === 'instructor' ? '/instructor/submissions.php?status=pending&assignment_id=' . (int) $item['id'] : $insights['queueUrl']);
                $overdue = $dashboardRole === 'student' && $item['date'] && strtotime($item['date']) < time();
            ?><a href="<?php echo sanitize(APP_URL . $itemUrl); ?>" class="dashboard-priority"><span class="dashboard-priority__icon"><i class="fas <?php echo $dashboardRole === 'admin' ? 'fa-user-check' : 'fa-list-check'; ?>" aria-hidden="true"></i></span><span><strong><?php echo sanitize($item['title']); ?></strong><small><?php echo sanitize($item['detail']); ?></small><small class="<?php echo $overdue ? 'text-danger' : ''; ?>"><?php echo $item['date'] ? ($overdue ? 'Overdue · ' : ($dashboardRole === 'student' ? 'Due ' : 'Received ')) . formatDate($item['date'], 'd M Y') : 'No deadline'; ?></small></span><i class="fas fa-arrow-right" aria-hidden="true"></i></a><?php endforeach; ?>
        </div><?php else: ?><div class="dashboard-clear"><i class="fas fa-circle-check" aria-hidden="true"></i><h3>All caught up</h3><p><?php echo sanitize($insights['empty']); ?></p></div><?php endif; ?>
        <a class="dashboard-panel-link" href="<?php echo sanitize(APP_URL . $insights['queueUrl']); ?>"><?php echo $dashboardRole === 'admin' ? 'Manage enrollments' : ($dashboardRole === 'instructor' ? 'Open grading workspace' : 'Open my courses'); ?> <i class="fas fa-arrow-right" aria-hidden="true"></i></a>
    </section>
</div>
