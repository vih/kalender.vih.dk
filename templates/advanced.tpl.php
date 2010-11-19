<p class="calendar-description"><?php e($data['X-WR-CALDESC']); ?></p>

<?php foreach ($events as $event): ?>
    <?php
    $start_date = new DateTime(date('Y-m-d H:i:s', $event['DTSTART']), new DateTimeZone($context->timezone));
    $end_date = new DateTime(date('Y-m-d H:i:s', $event['DTEND']), new DateTimeZone($context->timezone));
    ?>
	<h2><?php e($event['SUMMARY'])?></h2>
	<p><?php if (isset($event['DESCRIPTION'])) e($event['DESCRIPTION'])?></p>
	<p><?php e($start_date->format('j. ') . t($start_date->format('F')) . $start_date->format(', Y H:i')); ?>-<?php e($end_date->format('H:i'))?></p>
	<p><?php if (isset($event['LOCATION'])) e($event['LOCATION'])?></p>
	<hr>
<?php endforeach; ?>