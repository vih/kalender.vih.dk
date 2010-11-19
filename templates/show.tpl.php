<?php if ($context->getImageName()): ?>
	<img style="float:right; width: 200px; margin-left: 10px; margin-bottom: 10px;" src="<?php e(url('/uploads/' . $context->getImageName())); ?>">
<?php endif; ?>

<div class="vevent">
<?php if (isset($event->content)): ?>
	<p class="summary"><?php echo nl2br($event->content); ?></p>
<?php endif; ?>

<?php
    $start_date = new DateTime(date('Y-m-d H:i:s', strtotime($event->when[0]->startTime)), new DateTimeZone($context->getTimeZone()));
    $end_date = new DateTime(date('Y-m-d H:i:s', strtotime($event->when[0]->endTime)), new DateTimeZone($context->getTimeZone()));
?>

<p><span class="dtstart" title="<?php e($start_date->format('Y-m-d H:i')); ?>"><?php e(t($start_date->format('l'))); ?>, <?php e($start_date->format('j.')); ?> <?php e(t($start_date->format('F'))); ?>, <?php e($start_date->format('Y H:i')); ?></span>-<span class="dtend" title="<?php e($end_date->format('Y-m-d H:i')); ?>"><?php e($end_date->format('H:i'))?></span></p>
<p class="location"><?php if (isset($event->where[0]->valueString)) e($event->where[0]->valueString)?></p>
</div>
