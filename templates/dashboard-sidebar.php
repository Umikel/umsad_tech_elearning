<aside class="dashboard-sidebar" aria-label="Workspace sidebar">
    <a class="dashboard-sidebar__brand" href="<?php echo $siteUrl . $workspaceHome; ?>"><img src="<?php echo sanitize($versionedAsset('/assets/images/umsad-tech-logo.png')); ?>" alt="Umsad Tech" width="108" height="72"><span>Learn. Build. Grow.</span></a>
    <p class="dashboard-sidebar__label"><?php echo sanitize($workspaceLabel); ?></p>
    <nav aria-label="Dashboard sections">
        <?php foreach ($workspaceNavItems as $key => $item): ?><a href="<?php echo $siteUrl . $item['path']; ?>" <?php echo $workspaceNavActive === $key ? 'class="is-active" aria-current="page"' : ''; ?>><i class="fas <?php echo sanitize($item['icon']); ?>" aria-hidden="true"></i><?php echo sanitize($item['label']); ?></a><?php endforeach; ?>
    </nav>
    <div class="dashboard-sidebar__help"><i class="fas fa-circle-question" aria-hidden="true"></i><strong>A little help?</strong><p>We are here when you need us.</p><a href="<?php echo $siteUrl; ?>/contact.php">Contact support <i class="fas fa-arrow-right" aria-hidden="true"></i></a></div>
    <nav class="dashboard-sidebar__account" aria-label="Account"><a href="<?php echo $siteUrl; ?>/profile.php"><i class="fas fa-user" aria-hidden="true"></i>My profile</a><a href="<?php echo $siteUrl; ?>/settings.php"><i class="fas fa-gear" aria-hidden="true"></i>Settings</a><a href="<?php echo $siteUrl; ?>/logout.php"><i class="fas fa-arrow-right-from-bracket" aria-hidden="true"></i>Log out</a></nav>
</aside>
