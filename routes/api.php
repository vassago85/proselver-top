<?php

/*
 * Global API routes live here when added. Driver PWA sync endpoints live in
 * routes/driver.php under /driver/api/... so they inherit the same session
 * auth + driver.access middleware stack as the rest of the driver app
 * (see bootstrap/app.php), avoiding a separate token stack for what is a
 * same-origin installable web app.
 */
