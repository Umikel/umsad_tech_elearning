<?php
$guideHeadings = ['Website Development for Beginners', 'One-Month Practical HTML Course', 'About the Course', 'Who Can Apply?', 'What You Will Learn', 'How Every Live Class Will Work', 'Course Assessment', 'Applications and Software Required', 'How to Apply'];
?>
<dialog id="courseRegistrationGuide" class="registration-guide" aria-labelledby="courseGuideTitle" data-version="<?php echo sanitize($registrationGuide['version']); ?>">
    <header class="registration-guide__header"><span>Before you register</span><h2 id="courseGuideTitle" tabindex="-1">Read your course guide</h2><p>Please review the dates, timetable, equipment and participation requirements before continuing.</p></header>
    <button type="button" id="courseGuideClose" class="btn btn-outline-secondary m-3">Close guide</button>
    <div class="registration-guide__content">
        <p id="courseGuidePlan" class="alert alert-info">Optional Sunday physical class: ₦50,000 including the online course. Sundays, 10am–11am (Nigeria time). Venue to be announced.</p>
        <?php foreach ($registrationGuide['blocks'] as $block): ?>
            <?php if ($block['type'] === 'table'): ?><div class="table-responsive"><table class="table table-bordered"><caption class="visually-hidden">Weekly class timetable</caption><thead><tr><?php foreach ($block['rows'][0] as $cell): ?><th scope="col"><?php echo sanitize($cell); ?></th><?php endforeach; ?></tr></thead><tbody><?php foreach (array_slice($block['rows'], 1) as $row): ?><tr><?php foreach ($row as $cell): ?><td><?php echo nl2br(sanitize($cell)); ?></td><?php endforeach; ?></tr><?php endforeach; ?></tbody></table></div>
            <?php elseif (in_array($block['text'], $guideHeadings, true)): ?><h3><?php echo sanitize($block['text']); ?></h3>
            <?php else: ?><p><?php echo nl2br(sanitize($block['text'])); ?></p><?php endif; ?>
        <?php endforeach; ?>
        <p class="registration-guide__note"><strong>Before payment:</strong> The guide lists a course fee of ₦30,000. The current price<?php echo $pricing['has_discount'] ?? false ? ' (including any discount)' : ''; ?> shown on this course page is <?php echo formatCurrency($effectivePrice); ?>. This is the online-only price. If you select the Sunday physical class, the total registration fee is ₦50,000 including online learning.</p>
        <p class="registration-guide__note">After registration, your course will remain locked until an administrator approves your learning access.</p>
    </div>
    <form id="courseGuideForm" class="registration-guide__confirmation">
        <label for="courseGuideAccepted"><input type="checkbox" id="courseGuideAccepted" required><span>I have read and understood the course guide, including the dates, timetable, laptop requirement and participation expectations.</span></label>
        <div class="registration-guide__actions"><button type="button" id="courseGuideCancel" class="btn btn-outline-secondary">Not now</button><button type="submit" id="courseGuideContinue" class="btn btn-primary" disabled>Continue registration <i class="fas fa-arrow-right" aria-hidden="true"></i></button></div>
    </form>
</dialog>
<style>
.registration-guide { width: min(760px, calc(100% - 24px)); max-height: 90vh; max-height: 90dvh; padding: 0; border: 1px solid #e1deef; border-radius: 20px; color: #28283e; background: #fff; box-shadow: 0 24px 80px #15122c40; overscroll-behavior: contain; }
.registration-guide::backdrop { background: #17152dc9; }
.registration-guide__header { padding: 28px; background: #272440; color: #fff; }
.registration-guide__header > span { font-size: .75rem; color: #cdc4ff; text-transform: uppercase; letter-spacing: .1em; font-weight: 700; }
.registration-guide__header h2 { color: #fff; margin: 12px 0; font-size: clamp(1.4rem, 4vw, 2rem); }
.registration-guide__header p { color: #dfdbef; margin: 0; }
.registration-guide__content { padding: 28px; font-size: .94rem; line-height: 1.8; }
.registration-guide__content h3 { font-size: 1.15rem; margin: 28px 0 14px; line-height: 1.4; }
.registration-guide__content h3:first-child { margin-top: 0; }
.registration-guide__content p { margin: 0 0 14px; }
.registration-guide__content table { font-size: .85rem; min-width: 300px; }
.registration-guide__content th { background: #efedf9; }
.registration-guide__confirmation { padding: 24px 28px; background: #f6f5fb; border-top: 1px solid #e1deef; }
.registration-guide__confirmation label { display: flex; align-items: flex-start; gap: 12px; line-height: 1.6; cursor: pointer; }
.registration-guide__confirmation input { margin-top: 5px; width: 20px; height: 20px; flex: 0 0 20px; accent-color: #625bf6; }
.registration-guide__actions { display: flex; flex-wrap: wrap; justify-content: flex-end; gap: 12px; margin-top: 22px; }
.registration-guide__note { color: #57536f; }
@media(max-width: 480px) { .registration-guide__header,.registration-guide__content,.registration-guide__confirmation { padding: 20px; } .registration-guide__actions .btn { width: 100%; } }
</style>
