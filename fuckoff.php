<?php
// Include database connection
require_once 'db.php';   
session_start();

// Ensure database connection is active
if (isset($conn) && $conn instanceof mysqli) {
    $sid = 1; // User ID (student ID)
    $oid = 1; // Organization ID

    // Prepare delete statement using 'sid' as your foreign key to the user table
    $stmt = $conn->prepare("DELETE FROM membership WHERE sid = ? AND oid = ?");
    
    if ($stmt) {
        $stmt->bind_param("ii", $sid, $oid);
        
        if ($stmt->execute()) {
            echo "Successfully removed user UID {$sid} from organization OID {$oid}.";
        } else {
            echo "Error executing query: " . htmlspecialchars($stmt->error);
        }
        
        $stmt->close();
    } else {
        echo "Error preparing statement: " . htmlspecialchars($conn->error);
    }
} else {
    die("Database connection error.");
}
?>
