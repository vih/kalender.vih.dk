<h1>Publish event to Facebook</h1>

<form action="<?php e(url()); ?>" method="post">
	<?php if (!$context->isPublished()): ?>
	<input  value="Publish event to facebook" type="submit">
	<?php else: ?>
	<p><a href="">See event</a></p>
	<input  value="Force new publication facebook" type="submit" name="force" onclick="return confirm('Are you sure');">
	<?php endif; ?>
</form>
