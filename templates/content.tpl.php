<html>
	<head>
		<title><?php e($title); ?></title>
		<link rel="profile" href="http://microformats.org/profile/hcalendar">
		<style>
			.calendar-description {
				background: #eee;
				padding: 1em;
			}
		</style>
	</head>
	<body>
		<img src="<?php e(url('logo.jpg')); ?>">

		<h1><?php e($title); ?></h1>

		<ul>
			<?php foreach($options as $identifier => $url): ?>
			<li><a href="<?php e($url); ?>"><?php e($identifier); ?></a></li>
			<?php endforeach; ?>
		</ul>

        <?php echo $content; ?>

	</body>
</html>