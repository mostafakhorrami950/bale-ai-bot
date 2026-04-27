<?php
echo "Loaded Extensions: <br>";
foreach (get_loaded_extensions() as $ext) {
    echo "- $ext <br>";
}

echo "<br>PDO Drivers: <br>";
foreach (PDO::getAvailableDrivers() as $driver) {
    echo "- $driver <br>";
}