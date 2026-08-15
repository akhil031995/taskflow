        </main>
    </div><!-- /main column -->
</div><!-- /shell flex -->

<?php require __DIR__ . '/session_monitor.php'; ?>

<!-- Shared client logic: theme, quick links, global search, toast + API helper.
     ?v=filemtime busts the browser cache whenever the file changes. -->
<script src="assets/app.js?v=<?= @filemtime(__DIR__ . '/../assets/app.js') ?>"></script>
<script src="assets/session_monitor.js?v=<?= @filemtime(__DIR__ . '/../assets/session_monitor.js') ?>"></script>
</body>
</html>
