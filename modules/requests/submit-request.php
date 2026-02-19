<?php
// SUBMIT REQUEST CODE SNIPPET
// This code handles the form submission for new document requests

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['submit_request'])) {
    // Get the resident ID from the logged-in user's resident record
    $resident_id = $resident['resident_id'];
    
    // Get the request type ID from the form
    $request_type_id = intval($_POST['request_type_id']);
    
    // Get the main purpose from the form
    $purpose_text = sanitizeInput($_POST['purpose']);
    
    // Build comprehensive purpose text with all details
    $purpose = $purpose_text;
    
    // Add additional details if provided
    if (!empty($_POST['additional_details'])) {
        $purpose .= "\n\nAdditional Details:\n" . sanitizeInput($_POST['additional_details']);
    }
    
    // Add business details if provided (for Business Permit requests)
    if (!empty($_POST['business_name'])) {
        $purpose .= "\n\nBusiness Information:";
        $purpose .= "\nBusiness Name: " . sanitizeInput($_POST['business_name']);
        
        if (!empty($_POST['business_address'])) {
            $purpose .= "\nBusiness Address: " . sanitizeInput($_POST['business_address']);
        }
        if (!empty($_POST['business_type'])) {
            $purpose .= "\nBusiness Type: " . sanitizeInput($_POST['business_type']);
        }
    }
    
    // Add cedula details if provided (for Cedula requests)
    if (!empty($_POST['cedula_number']) || !empty($_POST['amount_paid'])) {
        $purpose .= "\n\nCedula Information:";
        if (!empty($_POST['cedula_number'])) {
            $purpose .= "\nCedula Number: " . sanitizeInput($_POST['cedula_number']);
        }
        if (!empty($_POST['amount_paid'])) {
            $purpose .= "\nAmount Paid: PHP " . number_format(floatval($_POST['amount_paid']), 2);
        }
    }
    
    // Set default status and payment status
    $status = 'Pending';
    $payment_status = 0; // 0 = Not paid, 1 = Paid
    
    // Prepare SQL INSERT statement
    // Columns: resident_id, request_type_id, purpose, status, payment_status
    // Note: request_date will be auto-filled by database (current_timestamp)
    $sql = "INSERT INTO tbl_requests 
            (resident_id, request_type_id, purpose, status, payment_status) 
            VALUES (?, ?, ?, ?, ?)";
    
    // Prepare the statement
    $stmt = $conn->prepare($sql);
    
    // Bind parameters
    // i = integer, s = string
    // Parameters: resident_id (int), request_type_id (int), purpose (string), status (string), payment_status (int)
    $stmt->bind_param("iissi", 
        $resident_id,       // integer
        $request_type_id,   // integer
        $purpose,           // string
        $status,            // string
        $payment_status     // integer
    );
    
    // Execute the statement
    if ($stmt->execute()) {
        // Success - redirect with success message
        $_SESSION['success_message'] = 'Request submitted successfully! Please wait for processing.';
        header('Location: manage.php');
        exit();
    } else {
        // Failed - show error message
        $error = 'Failed to submit request. Please try again.';
    }
    
    // Close the statement
    $stmt->close();
}
?>