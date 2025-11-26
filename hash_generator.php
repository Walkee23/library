<?php
// Temporary script to generate secure password hashes
echo 'Librarian Password Hash: ' . password_hash('alisonmillward123', PASSWORD_DEFAULT) . "\n";
echo 'Staff Password Hash: ' . password_hash('bobsmith123', PASSWORD_DEFAULT) . "\n";
echo 'Teacher Password Hash: ' . password_hash('anneroe123', PASSWORD_DEFAULT) . "\n";
echo 'Student 1 Password Hash: ' . password_hash('alicecoop123', PASSWORD_DEFAULT) . "\n";
echo 'Student 2 Password Hash: ' . password_hash('maurinefuller123', PASSWORD_DEFAULT) . "\n";
?>