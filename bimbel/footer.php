        </main>

        <footer class="footer">
            <span>&copy; <?= date('Y') ?> <?= e(APP_NAME) ?></span>
            <span>Sistem Informasi Bimbingan Belajar</span>
        </footer>
    </div><!-- /.app__main -->
</div><!-- /.app -->

<script>
    (function () {
        var app = document.getElementById('app');
        var toggle = document.getElementById('menuToggle');
        var overlay = document.getElementById('overlay');
        if (!app || !toggle) { return; }

        toggle.addEventListener('click', function () { app.classList.toggle('is-open'); });
        overlay.addEventListener('click', function () { app.classList.remove('is-open'); });
        document.addEventListener('keydown', function (e) {
            if (e.key === 'Escape') { app.classList.remove('is-open'); }
        });
    })();
</script>
</body>
</html>
