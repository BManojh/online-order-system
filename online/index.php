<?php
session_start();
require_once 'config.php';

// Check for notification
$notification = isset($_SESSION['notification']) ? $_SESSION['notification'] : null;
unset($_SESSION['notification']);

// Get statistics
$stats = [];
try {
    // Total Orders
    $stmt = $db->query("SELECT COUNT(*) as total_orders FROM orders");
    $stats['total_orders'] = $stmt->fetchColumn();

    // Total Revenue
    $stmt = $db->query("SELECT SUM(oi.quantity * oi.price) as total_revenue 
                        FROM order_items oi 
                        JOIN orders o ON oi.order_id = o.id 
                        JOIN payments p ON o.id = p.order_id 
                        WHERE p.status = 'completed'");
    $stats['total_revenue'] = $stmt->fetchColumn() ?: 0;

    // Active Customers
    $stmt = $db->query("SELECT COUNT(DISTINCT customer_id) as active_customers FROM orders");
    $stats['active_customers'] = $stmt->fetchColumn();

    // Products Available
    $stmt = $db->query("SELECT COUNT(*) as products_available FROM products WHERE stock > 0");
    $stats['products_available'] = $stmt->fetchColumn();
} catch(PDOException $e) {
    showNotification("Error fetching statistics: " . $e->getMessage(), 'error');
}

// Get current tab
$current_tab = isset($_GET['tab']) ? $_GET['tab'] : 'customers';

// Function to get all records from a table
function getAllRecords($db, $table, $orderBy = 'id') {
    try {
        $stmt = $db->query("SELECT * FROM $table ORDER BY $orderBy DESC");
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch(PDOException $e) {
        showNotification("Error fetching $table: " . $e->getMessage(), 'error');
        return [];
    }
}

// Function to get a single record by ID
function getRecordById($db, $table, $id) {
    try {
        $stmt = $db->prepare("SELECT * FROM $table WHERE id = ?");
        $stmt->execute([$id]);
        return $stmt->fetch(PDO::FETCH_ASSOC);
    } catch(PDOException $e) {
        showNotification("Error fetching $table record: " . $e->getMessage(), 'error');
        return null;
    }
}

// Handle form submissions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    switch($current_tab) {
        case 'customers':
            handleCustomerForm($db);
            break;
        case 'products':
            handleProductForm($db);
            break;
        case 'orders':
            handleOrderForm($db);
            break;
        case 'payments':
            handlePaymentForm($db);
            break;
        case 'shipping':
            handleShippingForm($db);
            break;
    }
}

// Customer form handler
function handleCustomerForm($db) {
    $id = isset($_POST['id']) ? intval($_POST['id']) : 0;
    $name = trim($_POST['name']);
    $email = trim($_POST['email']);
    $phone = trim($_POST['phone']);
    $address = trim($_POST['address']);

    try {
        if ($id > 0) {
            // Update existing customer
            $stmt = $db->prepare("UPDATE customers SET name = ?, email = ?, phone = ?, address = ? WHERE id = ?");
            $stmt->execute([$name, $email, $phone, $address, $id]);
            showNotification("Customer updated successfully!");
        } else {
            // Create new customer
            $stmt = $db->prepare("INSERT INTO customers (name, email, phone, address) VALUES (?, ?, ?, ?)");
            $stmt->execute([$name, $email, $phone, $address]);
            showNotification("Customer added successfully!");
        }
    } catch(PDOException $e) {
        showNotification("Error saving customer: " . $e->getMessage(), 'error');
    }
    redirect("index.php?tab=customers");
}

// Product form handler
function handleProductForm($db) {
    $id = isset($_POST['id']) ? intval($_POST['id']) : 0;
    $name = trim($_POST['name']);
    $description = trim($_POST['description']);
    $price = floatval($_POST['price']);
    $stock = intval($_POST['stock']);
    $category = trim($_POST['category']);

    try {
        if ($id > 0) {
            // Update existing product
            $stmt = $db->prepare("UPDATE products SET name = ?, description = ?, price = ?, stock = ?, category = ? WHERE id = ?");
            $stmt->execute([$name, $description, $price, $stock, $category, $id]);
            showNotification("Product updated successfully!");
        } else {
            // Create new product
            $stmt = $db->prepare("INSERT INTO products (name, description, price, stock, category) VALUES (?, ?, ?, ?, ?)");
            $stmt->execute([$name, $description, $price, $stock, $category]);
            showNotification("Product added successfully!");
        }
    } catch(PDOException $e) {
        showNotification("Error saving product: " . $e->getMessage(), 'error');
    }
    redirect("index.php?tab=products");
}

// Order form handler
function handleOrderForm($db) {
    $id = isset($_POST['id']) ? intval($_POST['id']) : 0;
    $customer_id = intval($_POST['customer_id']);
    $product_id = intval($_POST['product_id']);
    $quantity = intval($_POST['quantity']);
    $order_date = trim($_POST['order_date']);
    $status = trim($_POST['status']);

    try {
        // Get product price
        $stmt = $db->prepare("SELECT price, stock FROM products WHERE id = ?");
        $stmt->execute([$product_id]);
        $product = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$product) {
            throw new Exception("Product not found");
        }

        if ($product['stock'] < $quantity) {
            throw new Exception("Not enough stock available");
        }

        if ($id > 0) {
            // Update existing order
            $db->beginTransaction();
            
            // Update order
            $stmt = $db->prepare("UPDATE orders SET customer_id = ?, order_date = ?, status = ? WHERE id = ?");
            $stmt->execute([$customer_id, $order_date, $status, $id]);
            
            // Update order item
            $stmt = $db->prepare("UPDATE order_items SET product_id = ?, quantity = ?, price = ? WHERE order_id = ?");
            $stmt->execute([$product_id, $quantity, $product['price'], $id]);
            
            $db->commit();
            showNotification("Order updated successfully!");
        } else {
            // Create new order
            $db->beginTransaction();
            
            // Create order
            $stmt = $db->prepare("INSERT INTO orders (customer_id, order_date, status) VALUES (?, ?, ?)");
            $stmt->execute([$customer_id, $order_date, $status]);
            $order_id = $db->lastInsertId();
            
            // Create order item
            $stmt = $db->prepare("INSERT INTO order_items (order_id, product_id, quantity, price) VALUES (?, ?, ?, ?)");
            $stmt->execute([$order_id, $product_id, $quantity, $product['price']]);
            
            // Update product stock
            $stmt = $db->prepare("UPDATE products SET stock = stock - ? WHERE id = ?");
            $stmt->execute([$quantity, $product_id]);
            
            $db->commit();
            showNotification("Order created successfully!");
        }
    } catch(Exception $e) {
        $db->rollBack();
        showNotification("Error processing order: " . $e->getMessage(), 'error');
    }
    redirect("index.php?tab=orders");
}

// Payment form handler
function handlePaymentForm($db) {
    $id = isset($_POST['id']) ? intval($_POST['id']) : 0;
    $order_id = intval($_POST['order_id']);
    $amount = floatval($_POST['amount']);
    $method = trim($_POST['method']);
    $payment_date = trim($_POST['payment_date']);
    $status = trim($_POST['status']);

    try {
        if ($id > 0) {
            // Update existing payment
            $stmt = $db->prepare("UPDATE payments SET order_id = ?, amount = ?, method = ?, payment_date = ?, status = ? WHERE id = ?");
            $stmt->execute([$order_id, $amount, $method, $payment_date, $status, $id]);
            showNotification("Payment updated successfully!");
        } else {
            // Create new payment
            $stmt = $db->prepare("INSERT INTO payments (order_id, amount, method, payment_date, status) VALUES (?, ?, ?, ?, ?)");
            $stmt->execute([$order_id, $amount, $method, $payment_date, $status]);
            showNotification("Payment recorded successfully!");
        }
    } catch(PDOException $e) {
        showNotification("Error saving payment: " . $e->getMessage(), 'error');
    }
    redirect("index.php?tab=payments");
}

// Shipping form handler
function handleShippingForm($db) {
    $id = isset($_POST['id']) ? intval($_POST['id']) : 0;
    $order_id = intval($_POST['order_id']);
    $carrier = trim($_POST['carrier']);
    $tracking_number = trim($_POST['tracking_number']);
    $ship_date = trim($_POST['ship_date']);
    $status = trim($_POST['status']);

    try {
        if ($id > 0) {
            // Update existing shipping
            $stmt = $db->prepare("UPDATE shipping SET order_id = ?, carrier = ?, tracking_number = ?, ship_date = ?, status = ? WHERE id = ?");
            $stmt->execute([$order_id, $carrier, $tracking_number, $ship_date, $status, $id]);
            
            // Update order status if shipped
            if ($status === 'shipped') {
                $stmt = $db->prepare("UPDATE orders SET status = 'shipped' WHERE id = ?");
                $stmt->execute([$order_id]);
            }
            
            showNotification("Shipping updated successfully!");
        } else {
            // Create new shipping
            $stmt = $db->prepare("INSERT INTO shipping (order_id, carrier, tracking_number, ship_date, status) VALUES (?, ?, ?, ?, ?)");
            $stmt->execute([$order_id, $carrier, $tracking_number, $ship_date, $status]);
            
            // Update order status if shipped
            if ($status === 'shipped') {
                $stmt = $db->prepare("UPDATE orders SET status = 'shipped' WHERE id = ?");
                $stmt->execute([$order_id]);
            }
            
            showNotification("Shipping created successfully!");
        }
    } catch(PDOException $e) {
        showNotification("Error saving shipping: " . $e->getMessage(), 'error');
    }
    redirect("index.php?tab=shipping");
}

// Handle record deletions
if (isset($_GET['delete'])) {
    $id = intval($_GET['id']);
    
    switch($_GET['delete']) {
        case 'customer':
            try {
                $stmt = $db->prepare("DELETE FROM customers WHERE id = ?");
                $stmt->execute([$id]);
                showNotification("Customer deleted successfully!");
            } catch(PDOException $e) {
                showNotification("Error deleting customer: " . $e->getMessage(), 'error');
            }
            redirect("index.php?tab=customers");
            break;
            
        case 'product':
            try {
                $stmt = $db->prepare("DELETE FROM products WHERE id = ?");
                $stmt->execute([$id]);
                showNotification("Product deleted successfully!");
            } catch(PDOException $e) {
                showNotification("Error deleting product: " . $e->getMessage(), 'error');
            }
            redirect("index.php?tab=products");
            break;
            
        case 'order':
            try {
                $db->beginTransaction();
                
                // Delete order items first
                $stmt = $db->prepare("DELETE FROM order_items WHERE order_id = ?");
                $stmt->execute([$id]);
                
                // Then delete the order
                $stmt = $db->prepare("DELETE FROM orders WHERE id = ?");
                $stmt->execute([$id]);
                
                $db->commit();
                showNotification("Order deleted successfully!");
            } catch(PDOException $e) {
                $db->rollBack();
                showNotification("Error deleting order: " . $e->getMessage(), 'error');
            }
            redirect("index.php?tab=orders");
            break;
            
        case 'payment':
            try {
                $stmt = $db->prepare("DELETE FROM payments WHERE id = ?");
                $stmt->execute([$id]);
                showNotification("Payment deleted successfully!");
            } catch(PDOException $e) {
                showNotification("Error deleting payment: " . $e->getMessage(), 'error');
            }
            redirect("index.php?tab=payments");
            break;
            
        case 'shipping':
            try {
                $stmt = $db->prepare("DELETE FROM shipping WHERE id = ?");
                $stmt->execute([$id]);
                showNotification("Shipping deleted successfully!");
            } catch(PDOException $e) {
                showNotification("Error deleting shipping: " . $e->getMessage(), 'error');
            }
            redirect("index.php?tab=shipping");
            break;
    }
}

// Get data for current tab
$customers = getAllRecords($db, 'customers', 'name');
$products = getAllRecords($db, 'products', 'name');
$orders = [];
$payments = getAllRecords($db, 'payments');
$shipping = getAllRecords($db, 'shipping');

// Get orders with customer and product details
try {
    $stmt = $db->query("
        SELECT o.id, o.order_date, o.status, 
               c.name as customer_name, c.email as customer_email,
               p.name as product_name, oi.quantity, oi.price
        FROM orders o
        JOIN customers c ON o.customer_id = c.id
        JOIN order_items oi ON o.id = oi.order_id
        JOIN products p ON oi.product_id = p.id
        ORDER BY o.order_date DESC
    ");
    $orders = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch(PDOException $e) {
    showNotification("Error fetching orders: " . $e->getMessage(), 'error');
}

// Get edit record if requested
$edit_record = null;
if (isset($_GET['edit'])) {
    switch($_GET['edit']) {
        case 'customer':
            $edit_record = getRecordById($db, 'customers', $_GET['id']);
            break;
        case 'product':
            $edit_record = getRecordById($db, 'products', $_GET['id']);
            break;
        case 'order':
            $edit_record = getRecordById($db, 'orders', $_GET['id']);
            break;
        case 'payment':
            $edit_record = getRecordById($db, 'payments', $_GET['id']);
            break;
        case 'shipping':
            $edit_record = getRecordById($db, 'shipping', $_GET['id']);
            break;
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Online Order Processing System</title>
    <style>
        :root {
            --primary-color: #3498db;
            --secondary-color: #2ecc71;
            --danger-color: #e74c3c;
            --warning-color: #f39c12;
            --dark-color: #2c3e50;
            --light-color: #ecf0f1;
            --shadow: 0 4px 6px rgba(0, 0, 0, 0.1);
            --transition: all 0.3s ease;
        }

        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
        }

        body {
            background-color: #f5f7fa;
            color: #333;
            line-height: 1.6;
        }

        .container {
            max-width: 1200px;
            margin: 0 auto;
            padding: 20px;
        }

        header {
            background: linear-gradient(135deg, var(--primary-color), var(--secondary-color));
            color: white;
            padding: 20px 0;
            text-align: center;
            margin-bottom: 30px;
            border-radius: 8px;
            box-shadow: var(--shadow);
        }

        h1 {
            font-size: 2.5rem;
            margin-bottom: 10px;
        }

        .subtitle {
            font-size: 1.2rem;
            opacity: 0.9;
        }

        .dashboard {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(300px, 1fr));
            gap: 20px;
            margin-bottom: 30px;
        }

        .card {
            background: white;
            border-radius: 8px;
            padding: 20px;
            box-shadow: var(--shadow);
            transition: var(--transition);
        }

        .card:hover {
            transform: translateY(-5px);
            box-shadow: 0 10px 20px rgba(0, 0, 0, 0.1);
        }

        .card h2 {
            color: var(--dark-color);
            margin-bottom: 15px;
            font-size: 1.5rem;
            border-bottom: 2px solid var(--light-color);
            padding-bottom: 10px;
        }

        .form-group {
            margin-bottom: 15px;
        }

        label {
            display: block;
            margin-bottom: 5px;
            font-weight: 600;
            color: var(--dark-color);
        }

        input, select, textarea {
            width: 100%;
            padding: 10px;
            border: 1px solid #ddd;
            border-radius: 4px;
            font-size: 1rem;
            transition: var(--transition);
        }

        input:focus, select:focus, textarea:focus {
            outline: none;
            border-color: var(--primary-color);
            box-shadow: 0 0 0 2px rgba(52, 152, 219, 0.2);
        }

        button {
            background-color: var(--primary-color);
            color: white;
            border: none;
            padding: 10px 20px;
            border-radius: 4px;
            cursor: pointer;
            font-size: 1rem;
            transition: var(--transition);
        }

        button:hover {
            background-color: #2980b9;
            transform: translateY(-2px);
        }

        .btn-secondary {
            background-color: var(--secondary-color);
        }

        .btn-secondary:hover {
            background-color: #27ae60;
        }

        .btn-danger {
            background-color: var(--danger-color);
        }

        .btn-danger:hover {
            background-color: #c0392b;
        }

        table {
            width: 100%;
            border-collapse: collapse;
            margin-top: 20px;
        }

        th, td {
            padding: 12px 15px;
            text-align: left;
            border-bottom: 1px solid #ddd;
        }

        th {
            background-color: var(--primary-color);
            color: white;
            font-weight: 600;
        }

        tr:nth-child(even) {
            background-color: #f9f9f9;
        }

        tr:hover {
            background-color: #f1f1f1;
        }

        .actions {
            display: flex;
            gap: 5px;
        }

        .tab-container {
            margin-bottom: 20px;
        }

        .tabs {
            display: flex;
            list-style: none;
            margin-bottom: -1px;
        }

        .tab {
            padding: 10px 20px;
            cursor: pointer;
            background-color: #f1f1f1;
            border: 1px solid #ddd;
            border-bottom: none;
            border-radius: 5px 5px 0 0;
            margin-right: 5px;
            transition: var(--transition);
        }

        .tab.active {
            background-color: white;
            border-bottom: 1px solid white;
            color: var(--primary-color);
            font-weight: bold;
        }

        .tab-content {
            display: none;
            padding: 20px;
            background-color: white;
            border: 1px solid #ddd;
            border-radius: 0 5px 5px 5px;
        }

        .tab-content.active {
            display: block;
        }

        .stats {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 20px;
            margin-bottom: 30px;
        }

        .stat-card {
            background: white;
            border-radius: 8px;
            padding: 20px;
            box-shadow: var(--shadow);
            text-align: center;
        }

        .stat-card h3 {
            color: var(--dark-color);
            margin-bottom: 10px;
        }

        .stat-value {
            font-size: 2rem;
            font-weight: bold;
            color: var(--primary-color);
        }

        .search-bar {
            margin-bottom: 20px;
        }

        .search-bar input {
            max-width: 400px;
            padding: 10px 15px;
            border-radius: 20px;
        }

        .notification {
            position: fixed;
            top: 20px;
            right: 20px;
            padding: 15px 20px;
            color: white;
            border-radius: 5px;
            box-shadow: var(--shadow);
            transform: translateX(200%);
            transition: transform 0.3s ease;
            z-index: 1000;
        }

        .notification.show {
            transform: translateX(0);
        }

        .notification.success {
            background-color: var(--secondary-color);
        }

        .notification.error {
            background-color: var(--danger-color);
        }

        .status-badge {
            padding: 5px 10px;
            border-radius: 20px;
            font-size: 0.8rem;
            font-weight: bold;
            text-transform: capitalize;
        }

        .status-badge.pending {
            background-color: #f39c12;
            color: white;
        }

        .status-badge.processing {
            background-color: #3498db;
            color: white;
        }

        .status-badge.shipped {
            background-color: #9b59b6;
            color: white;
        }

        .status-badge.delivered {
            background-color: #2ecc71;
            color: white;
        }

        .status-badge.cancelled {
            background-color: #e74c3c;
            color: white;
        }

        .status-badge.completed {
            background-color: #2ecc71;
            color: white;
        }

        .status-badge.failed {
            background-color: #e74c3c;
            color: white;
        }

        .status-badge.refunded {
            background-color: #f39c12;
            color: white;
        }

        .status-badge.preparing {
            background-color: #3498db;
            color: white;
        }

        .status-badge.in_transit {
            background-color: #9b59b6;
            color: white;
        }

        .status-badge.returned {
            background-color: #e74c3c;
            color: white;
        }

        @media (max-width: 768px) {
            .dashboard {
                grid-template-columns: 1fr;
            }
            
            .stats {
                grid-template-columns: 1fr;
            }
            
            .tabs {
                flex-wrap: wrap;
            }
            
            .tab {
                flex: 1;
                text-align: center;
                margin-bottom: 5px;
            }
        }

        /* Animation */
        @keyframes fadeIn {
            from { opacity: 0; transform: translateY(20px); }
            to { opacity: 1; transform: translateY(0); }
        }

        .card, .stat-card, .tab-content {
            animation: fadeIn 0.5s ease-out forwards;
        }
    </style>
</head>
<body>
    <div class="container">
        <header>
            <h1>Online Order Processing System</h1>
            <p class="subtitle">Manage customers, products, orders, payments, and shipping</p>
        </header>

        <div class="stats">
            <div class="stat-card">
                <h3>Total Orders</h3>
                <div class="stat-value"><?php echo $stats['total_orders']; ?></div>
            </div>
            <div class="stat-card">
                <h3>Total Revenue</h3>
                <div class="stat-value">$<?php echo number_format($stats['total_revenue'], 2); ?></div>
            </div>
            <div class="stat-card">
                <h3>Active Customers</h3>
                <div class="stat-value"><?php echo $stats['active_customers']; ?></div>
            </div>
            <div class="stat-card">
                <h3>Products Available</h3>
                <div class="stat-value"><?php echo $stats['products_available']; ?></div>
            </div>
        </div>

        <div class="tab-container">
            <ul class="tabs">
                <li class="tab <?php echo $current_tab === 'customers' ? 'active' : ''; ?>">
                    <a href="index.php?tab=customers">Customers</a>
                </li>
                <li class="tab <?php echo $current_tab === 'products' ? 'active' : ''; ?>">
                    <a href="index.php?tab=products">Products</a>
                </li>
                <li class="tab <?php echo $current_tab === 'orders' ? 'active' : ''; ?>">
                    <a href="index.php?tab=orders">Orders</a>
                </li>
                <li class="tab <?php echo $current_tab === 'payments' ? 'active' : ''; ?>">
                    <a href="index.php?tab=payments">Payments</a>
                </li>
                <li class="tab <?php echo $current_tab === 'shipping' ? 'active' : ''; ?>">
                    <a href="index.php?tab=shipping">Shipping</a>
                </li>
            </ul>

            <?php if ($notification): ?>
                <div class="notification show <?php echo $notification['type']; ?>">
                    <?php echo $notification['message']; ?>
                </div>
            <?php endif; ?>

            <div id="customers" class="tab-content <?php echo $current_tab === 'customers' ? 'active' : ''; ?>">
                <div class="search-bar">
                    <input type="text" id="customer-search" placeholder="Search customers...">
                </div>
                <div class="dashboard">
                    <div class="card">
                        <h2><?php echo isset($edit_record) && $_GET['edit'] === 'customer' ? 'Edit Customer' : 'Add New Customer'; ?></h2>
                        <form method="POST" action="index.php?tab=customers">
                            <?php if (isset($edit_record) && $_GET['edit'] === 'customer'): ?>
                                <input type="hidden" name="id" value="<?php echo $edit_record['id']; ?>">
                            <?php endif; ?>
                            <div class="form-group">
                                <label for="customer-name">Full Name</label>
                                <input type="text" id="customer-name" name="name" required 
                                       value="<?php echo isset($edit_record) && $_GET['edit'] === 'customer' ? htmlspecialchars($edit_record['name']) : ''; ?>">
                            </div>
                            <div class="form-group">
                                <label for="customer-email">Email</label>
                                <input type="email" id="customer-email" name="email" required
                                       value="<?php echo isset($edit_record) && $_GET['edit'] === 'customer' ? htmlspecialchars($edit_record['email']) : ''; ?>">
                            </div>
                            <div class="form-group">
                                <label for="customer-phone">Phone</label>
                                <input type="tel" id="customer-phone" name="phone"
                                       value="<?php echo isset($edit_record) && $_GET['edit'] === 'customer' ? htmlspecialchars($edit_record['phone']) : ''; ?>">
                            </div>
                            <div class="form-group">
                                <label for="customer-address">Address</label>
                                <textarea id="customer-address" name="address" rows="3"><?php echo isset($edit_record) && $_GET['edit'] === 'customer' ? htmlspecialchars($edit_record['address']) : ''; ?></textarea>
                            </div>
                            <button type="submit"><?php echo isset($edit_record) && $_GET['edit'] === 'customer' ? 'Update Customer' : 'Add Customer'; ?></button>
                            <?php if (isset($edit_record) && $_GET['edit'] === 'customer'): ?>
                                <a href="index.php?tab=customers" class="btn-secondary" style="text-decoration: none; display: inline-block; text-align: center;">Cancel</a>
                            <?php endif; ?>
                        </form>
                    </div>
                    <div class="card">
                        <h2>Customer List</h2>
                        <table id="customer-table">
                            <thead>
                                <tr>
                                    <th>ID</th>
                                    <th>Name</th>
                                    <th>Email</th>
                                    <th>Phone</th>
                                    <th>Actions</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($customers as $customer): ?>
                                    <tr>
                                        <td><?php echo $customer['id']; ?></td>
                                        <td><?php echo htmlspecialchars($customer['name']); ?></td>
                                        <td><?php echo htmlspecialchars($customer['email']); ?></td>
                                        <td><?php echo htmlspecialchars($customer['phone'] ?: 'N/A'); ?></td>
                                        <td class="actions">
                                            <a href="index.php?tab=customers&edit=customer&id=<?php echo $customer['id']; ?>" class="btn-secondary">Edit</a>
                                            <a href="index.php?tab=customers&delete=customer&id=<?php echo $customer['id']; ?>" class="btn-danger" onclick="return confirm('Are you sure you want to delete this customer?')">Delete</a>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>

            <div id="products" class="tab-content <?php echo $current_tab === 'products' ? 'active' : ''; ?>">
                <div class="search-bar">
                    <input type="text" id="product-search" placeholder="Search products...">
                </div>
                <div class="dashboard">
                    <div class="card">
                        <h2><?php echo isset($edit_record) && $_GET['edit'] === 'product' ? 'Edit Product' : 'Add New Product'; ?></h2>
                        <form method="POST" action="index.php?tab=products">
                            <?php if (isset($edit_record) && $_GET['edit'] === 'product'): ?>
                                <input type="hidden" name="id" value="<?php echo $edit_record['id']; ?>">
                            <?php endif; ?>
                            <div class="form-group">
                                <label for="product-name">Product Name</label>
                                <input type="text" id="product-name" name="name" required
                                       value="<?php echo isset($edit_record) && $_GET['edit'] === 'product' ? htmlspecialchars($edit_record['name']) : ''; ?>">
                            </div>
                            <div class="form-group">
                                <label for="product-description">Description</label>
                                <textarea id="product-description" name="description" rows="3"><?php echo isset($edit_record) && $_GET['edit'] === 'product' ? htmlspecialchars($edit_record['description']) : ''; ?></textarea>
                            </div>
                            <div class="form-group">
                                <label for="product-price">Price</label>
                                <input type="number" id="product-price" name="price" step="0.01" required
                                       value="<?php echo isset($edit_record) && $_GET['edit'] === 'product' ? htmlspecialchars($edit_record['price']) : ''; ?>">
                            </div>
                            <div class="form-group">
                                <label for="product-stock">Stock Quantity</label>
                                <input type="number" id="product-stock" name="stock" required
                                       value="<?php echo isset($edit_record) && $_GET['edit'] === 'product' ? htmlspecialchars($edit_record['stock']) : ''; ?>">
                            </div>
                            <div class="form-group">
                                <label for="product-category">Category</label>
                                <select id="product-category" name="category">
                                    <option value="electronics" <?php echo isset($edit_record) && $_GET['edit'] === 'product' && $edit_record['category'] === 'electronics' ? 'selected' : ''; ?>>Electronics</option>
                                    <option value="clothing" <?php echo isset($edit_record) && $_GET['edit'] === 'product' && $edit_record['category'] === 'clothing' ? 'selected' : ''; ?>>Clothing</option>
                                    <option value="home" <?php echo isset($edit_record) && $_GET['edit'] === 'product' && $edit_record['category'] === 'home' ? 'selected' : ''; ?>>Home & Garden</option>
                                    <option value="books" <?php echo isset($edit_record) && $_GET['edit'] === 'product' && $edit_record['category'] === 'books' ? 'selected' : ''; ?>>Books</option>
                                    <option value="other" <?php echo isset($edit_record) && $_GET['edit'] === 'product' && $edit_record['category'] === 'other' ? 'selected' : ''; ?>>Other</option>
                                </select>
                            </div>
                            <button type="submit"><?php echo isset($edit_record) && $_GET['edit'] === 'product' ? 'Update Product' : 'Add Product'; ?></button>
                            <?php if (isset($edit_record) && $_GET['edit'] === 'product'): ?>
                                <a href="index.php?tab=products" class="btn-secondary" style="text-decoration: none; display: inline-block; text-align: center;">Cancel</a>
                            <?php endif; ?>
                        </form>
                    </div>
                    <div class="card">
                        <h2>Product Inventory</h2>
                        <table id="product-table">
                            <thead>
                                <tr>
                                    <th>ID</th>
                                    <th>Name</th>
                                    <th>Price</th>
                                    <th>Stock</th>
                                    <th>Category</th>
                                    <th>Actions</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($products as $product): ?>
                                    <tr>
                                        <td><?php echo $product['id']; ?></td>
                                        <td><?php echo htmlspecialchars($product['name']); ?></td>
                                        <td>$<?php echo number_format($product['price'], 2); ?></td>
                                        <td><?php echo $product['stock']; ?></td>
                                        <td><?php echo ucfirst($product['category']); ?></td>
                                        <td class="actions">
                                            <a href="index.php?tab=products&edit=product&id=<?php echo $product['id']; ?>" class="btn-secondary">Edit</a>
                                            <a href="index.php?tab=products&delete=product&id=<?php echo $product['id']; ?>" class="btn-danger" onclick="return confirm('Are you sure you want to delete this product?')">Delete</a>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>

            <div id="orders" class="tab-content <?php echo $current_tab === 'orders' ? 'active' : ''; ?>">
                <div class="search-bar">
                    <input type="text" id="order-search" placeholder="Search orders...">
                </div>
                <div class="dashboard">
                    <div class="card">
                        <h2><?php echo isset($edit_record) && $_GET['edit'] === 'order' ? 'Edit Order' : 'Create New Order'; ?></h2>
                        <form method="POST" action="index.php?tab=orders">
                            <?php if (isset($edit_record) && $_GET['edit'] === 'order'): ?>
                                <input type="hidden" name="id" value="<?php echo $edit_record['id']; ?>">
                            <?php endif; ?>
                            <div class="form-group">
                                <label for="order-customer">Customer</label>
                                <select id="order-customer" name="customer_id" required>
                                    <?php foreach ($customers as $customer): ?>
                                        <option value="<?php echo $customer['id']; ?>" 
                                            <?php echo isset($edit_record) && $_GET['edit'] === 'order' && $edit_record['customer_id'] == $customer['id'] ? 'selected' : ''; ?>>
                                            <?php echo htmlspecialchars($customer['name']); ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="form-group">
                                <label for="order-product">Product</label>
                                <select id="order-product" name="product_id" required>
                                    <?php foreach ($products as $product): ?>
                                        <option value="<?php echo $product['id']; ?>">
                                            <?php echo htmlspecialchars($product['name']); ?> ($<?php echo number_format($product['price'], 2); ?>)
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="form-group">
                                <label for="order-quantity">Quantity</label>
                                <input type="number" id="order-quantity" name="quantity" min="1" required
                                       value="<?php echo isset($edit_record) && $_GET['edit'] === 'order' ? '1' : '1'; ?>">
                            </div>
                            <div class="form-group">
                                <label for="order-date">Order Date</label>
                                <input type="date" id="order-date" name="order_date" required
                                       value="<?php echo isset($edit_record) && $_GET['edit'] === 'order' ? $edit_record['order_date'] : date('Y-m-d'); ?>">
                            </div>
                            <div class="form-group">
                                <label for="order-status">Status</label>
                                <select id="order-status" name="status">
                                    <option value="pending" <?php echo isset($edit_record) && $_GET['edit'] === 'order' && $edit_record['status'] === 'pending' ? 'selected' : ''; ?>>Pending</option>
                                    <option value="processing" <?php echo isset($edit_record) && $_GET['edit'] === 'order' && $edit_record['status'] === 'processing' ? 'selected' : ''; ?>>Processing</option>
                                    <option value="shipped" <?php echo isset($edit_record) && $_GET['edit'] === 'order' && $edit_record['status'] === 'shipped' ? 'selected' : ''; ?>>Shipped</option>
                                    <option value="delivered" <?php echo isset($edit_record) && $_GET['edit'] === 'order' && $edit_record['status'] === 'delivered' ? 'selected' : ''; ?>>Delivered</option>
                                    <option value="cancelled" <?php echo isset($edit_record) && $_GET['edit'] === 'order' && $edit_record['status'] === 'cancelled' ? 'selected' : ''; ?>>Cancelled</option>
                                </select>
                            </div>
                            <button type="submit"><?php echo isset($edit_record) && $_GET['edit'] === 'order' ? 'Update Order' : 'Create Order'; ?></button>
                            <?php if (isset($edit_record) && $_GET['edit'] === 'order'): ?>
                                <a href="index.php?tab=orders" class="btn-secondary" style="text-decoration: none; display: inline-block; text-align: center;">Cancel</a>
                            <?php endif; ?>
                        </form>
                    </div>
                    <div class="card">
                        <h2>Order History</h2>
                        <table id="order-table">
                            <thead>
                                <tr>
                                    <th>Order ID</th>
                                    <th>Customer</th>
                                    <th>Product</th>
                                    <th>Quantity</th>
                                    <th>Total</th>
                                    <th>Date</th>
                                    <th>Status</th>
                                    <th>Actions</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($orders as $order): ?>
                                    <tr>
                                        <td><?php echo $order['id']; ?></td>
                                        <td><?php echo htmlspecialchars($order['customer_name']); ?></td>
                                        <td><?php echo htmlspecialchars($order['product_name']); ?></td>
                                        <td><?php echo $order['quantity']; ?></td>
                                        <td>$<?php echo number_format($order['quantity'] * $order['price'], 2); ?></td>
                                        <td><?php echo date('M j, Y', strtotime($order['order_date'])); ?></td>
                                        <td>
                                            <span class="status-badge <?php echo $order['status']; ?>">
                                                <?php echo ucfirst($order['status']); ?>
                                            </span>
                                        </td>
                                        <td class="actions">
                                            <a href="index.php?tab=orders&edit=order&id=<?php echo $order['id']; ?>" class="btn-secondary">Edit</a>
                                            <a href="index.php?tab=orders&delete=order&id=<?php echo $order['id']; ?>" class="btn-danger" onclick="return confirm('Are you sure you want to delete this order?')">Delete</a>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>

            <div id="payments" class="tab-content <?php echo $current_tab === 'payments' ? 'active' : ''; ?>">
                <div class="search-bar">
                    <input type="text" id="payment-search" placeholder="Search payments...">
                </div>
                <div class="dashboard">
                    <div class="card">
                        <h2><?php echo isset($edit_record) && $_GET['edit'] === 'payment' ? 'Edit Payment' : 'Record Payment'; ?></h2>
                        <form method="POST" action="index.php?tab=payments">
                            <?php if (isset($edit_record) && $_GET['edit'] === 'payment'): ?>
                                <input type="hidden" name="id" value="<?php echo $edit_record['id']; ?>">
                            <?php endif; ?>
                            <div class="form-group">
                                <label for="payment-order">Order</label>
                                <select id="payment-order" name="order_id" required>
                                    <?php foreach ($orders as $order): ?>
                                        <option value="<?php echo $order['id']; ?>"
                                            <?php echo isset($edit_record) && $_GET['edit'] === 'payment' && $edit_record['order_id'] == $order['id'] ? 'selected' : ''; ?>>
                                            Order #<?php echo $order['id']; ?> (<?php echo htmlspecialchars($order['customer_name']); ?> - $<?php echo number_format($order['quantity'] * $order['price'], 2); ?>)
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="form-group">
                                <label for="payment-amount">Amount</label>
                                <input type="number" id="payment-amount" name="amount" step="0.01" required
                                       value="<?php echo isset($edit_record) && $_GET['edit'] === 'payment' ? $edit_record['amount'] : ''; ?>">
                            </div>
                            <div class="form-group">
                                <label for="payment-method">Payment Method</label>
                                <select id="payment-method" name="method" required>
                                    <option value="credit_card" <?php echo isset($edit_record) && $_GET['edit'] === 'payment' && $edit_record['method'] === 'credit_card' ? 'selected' : ''; ?>>Credit Card</option>
                                    <option value="debit_card" <?php echo isset($edit_record) && $_GET['edit'] === 'payment' && $edit_record['method'] === 'debit_card' ? 'selected' : ''; ?>>Debit Card</option>
                                    <option value="paypal" <?php echo isset($edit_record) && $_GET['edit'] === 'payment' && $edit_record['method'] === 'paypal' ? 'selected' : ''; ?>>PayPal</option>
                                    <option value="bank_transfer" <?php echo isset($edit_record) && $_GET['edit'] === 'payment' && $edit_record['method'] === 'bank_transfer' ? 'selected' : ''; ?>>Bank Transfer</option>
                                    <option value="cash" <?php echo isset($edit_record) && $_GET['edit'] === 'payment' && $edit_record['method'] === 'cash' ? 'selected' : ''; ?>>Cash</option>
                                </select>
                            </div>
                            <div class="form-group">
                                <label for="payment-date">Payment Date</label>
                                <input type="date" id="payment-date" name="payment_date" required
                                       value="<?php echo isset($edit_record) && $_GET['edit'] === 'payment' ? $edit_record['payment_date'] : date('Y-m-d'); ?>">
                            </div>
                            <div class="form-group">
                                <label for="payment-status">Status</label>
                                <select id="payment-status" name="status">
                                    <option value="pending" <?php echo isset($edit_record) && $_GET['edit'] === 'payment' && $edit_record['status'] === 'pending' ? 'selected' : ''; ?>>Pending</option>
                                    <option value="completed" <?php echo isset($edit_record) && $_GET['edit'] === 'payment' && $edit_record['status'] === 'completed' ? 'selected' : ''; ?>>Completed</option>
                                    <option value="failed" <?php echo isset($edit_record) && $_GET['edit'] === 'payment' && $edit_record['status'] === 'failed' ? 'selected' : ''; ?>>Failed</option>
                                    <option value="refunded" <?php echo isset($edit_record) && $_GET['edit'] === 'payment' && $edit_record['status'] === 'refunded' ? 'selected' : ''; ?>>Refunded</option>
                                </select>
                            </div>
                            <button type="submit"><?php echo isset($edit_record) && $_GET['edit'] === 'payment' ? 'Update Payment' : 'Record Payment'; ?></button>
                            <?php if (isset($edit_record) && $_GET['edit'] === 'payment'): ?>
                                <a href="index.php?tab=payments" class="btn-secondary" style="text-decoration: none; display: inline-block; text-align: center;">Cancel</a>
                            <?php endif; ?>
                        </form>
                    </div>
                    <div class="card">
                        <h2>Payment Records</h2>
                        <table id="payment-table">
                            <thead>
                                <tr>
                                    <th>Payment ID</th>
                                    <th>Order ID</th>
                                    <th>Amount</th>
                                    <th>Method</th>
                                    <th>Date</th>
                                    <th>Status</th>
                                    <th>Actions</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($payments as $payment): ?>
                                    <tr>
                                        <td><?php echo $payment['id']; ?></td>
                                        <td><?php echo $payment['order_id']; ?></td>
                                        <td>$<?php echo number_format($payment['amount'], 2); ?></td>
                                        <td><?php echo ucfirst(str_replace('_', ' ', $payment['method'])); ?></td>
                                        <td><?php echo date('M j, Y', strtotime($payment['payment_date'])); ?></td>
                                        <td>
                                            <span class="status-badge <?php echo $payment['status']; ?>">
                                                <?php echo ucfirst($payment['status']); ?>
                                            </span>
                                        </td>
                                        <td class="actions">
                                            <a href="index.php?tab=payments&edit=payment&id=<?php echo $payment['id']; ?>" class="btn-secondary">Edit</a>
                                            <a href="index.php?tab=payments&delete=payment&id=<?php echo $payment['id']; ?>" class="btn-danger" onclick="return confirm('Are you sure you want to delete this payment record?')">Delete</a>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>

            <div id="shipping" class="tab-content <?php echo $current_tab === 'shipping' ? 'active' : ''; ?>">
                <div class="search-bar">
                    <input type="text" id="shipping-search" placeholder="Search shipments...">
                </div>
                <div class="dashboard">
                    <div class="card">
                        <h2><?php echo isset($edit_record) && $_GET['edit'] === 'shipping' ? 'Edit Shipment' : 'Create Shipment'; ?></h2>
                        <form method="POST" action="index.php?tab=shipping">
                            <?php if (isset($edit_record) && $_GET['edit'] === 'shipping'): ?>
                            <input type="hidden" name="id" value="<?php echo $edit_record['id']; ?>">
                        <?php endif; ?>
                        <div class="form-group">
                            <label for="shipping-order">Order</label>
                            <select id="shipping-order" name="order_id" required>
                                <?php foreach ($orders as $order): ?>
                                    <option value="<?php echo $order['id']; ?>"
                                        <?php echo isset($edit_record) && $_GET['edit'] === 'shipping' && $edit_record['order_id'] == $order['id'] ? 'selected' : ''; ?>>
                                        Order #<?php echo $order['id']; ?> (<?php echo htmlspecialchars($order['customer_name']); ?>)
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="form-group">
                            <label for="shipping-carrier">Carrier</label>
                            <select id="shipping-carrier" name="carrier" required>
                                <option value="ups" <?php echo isset($edit_record) && $_GET['edit'] === 'shipping' && $edit_record['carrier'] === 'ups' ? 'selected' : ''; ?>>UPS</option>
                                <option value="fedex" <?php echo isset($edit_record) && $_GET['edit'] === 'shipping' && $edit_record['carrier'] === 'fedex' ? 'selected' : ''; ?>>FedEx</option>
                                <option value="usps" <?php echo isset($edit_record) && $_GET['edit'] === 'shipping' && $edit_record['carrier'] === 'usps' ? 'selected' : ''; ?>>USPS</option>
                                <option value="dhl" <?php echo isset($edit_record) && $_GET['edit'] === 'shipping' && $edit_record['carrier'] === 'dhl' ? 'selected' : ''; ?>>DHL</option>
                                <option value="other" <?php echo isset($edit_record) && $_GET['edit'] === 'shipping' && $edit_record['carrier'] === 'other' ? 'selected' : ''; ?>>Other</option>
                            </select>
                        </div>
                        <div class="form-group">
                            <label for="shipping-tracking">Tracking Number</label>
                            <input type="text" id="shipping-tracking" name="tracking_number" required
                                   value="<?php echo isset($edit_record) && $_GET['edit'] === 'shipping' ? htmlspecialchars($edit_record['tracking_number']) : ''; ?>">
                        </div>
                        <div class="form-group">
                            <label for="shipping-date">Ship Date</label>
                            <input type="date" id="shipping-date" name="ship_date" required
                                   value="<?php echo isset($edit_record) && $_GET['edit'] === 'shipping' ? $edit_record['ship_date'] : date('Y-m-d'); ?>">
                        </div>
                        <div class="form-group">
                            <label for="shipping-status">Status</label>
                            <select id="shipping-status" name="status">
                                <option value="preparing" <?php echo isset($edit_record) && $_GET['edit'] === 'shipping' && $edit_record['status'] === 'preparing' ? 'selected' : ''; ?>>Preparing</option>
                                <option value="shipped" <?php echo isset($edit_record) && $_GET['edit'] === 'shipping' && $edit_record['status'] === 'shipped' ? 'selected' : ''; ?>>Shipped</option>
                                <option value="in_transit" <?php echo isset($edit_record) && $_GET['edit'] === 'shipping' && $edit_record['status'] === 'in_transit' ? 'selected' : ''; ?>>In Transit</option>
                                <option value="delivered" <?php echo isset($edit_record) && $_GET['edit'] === 'shipping' && $edit_record['status'] === 'delivered' ? 'selected' : ''; ?>>Delivered</option>
                                <option value="returned" <?php echo isset($edit_record) && $_GET['edit'] === 'shipping' && $edit_record['status'] === 'returned' ? 'selected' : ''; ?>>Returned</option>
                            </select>
                        </div>
                        <button type="submit"><?php echo isset($edit_record) && $_GET['edit'] === 'shipping' ? 'Update Shipment' : 'Create Shipment'; ?></button>
                        <?php if (isset($edit_record) && $_GET['edit'] === 'shipping'): ?>
                            <a href="index.php?tab=shipping" class="btn-secondary" style="text-decoration: none; display: inline-block; text-align: center;">Cancel</a>
                        <?php endif; ?>
                    </form>
                </div>
                <div class="card">
                    <h2>Shipping Records</h2>
                    <table id="shipping-table">
                        <thead>
                            <tr>
                                <th>Shipment ID</th>
                                <th>Order ID</th>
                                <th>Carrier</th>
                                <th>Tracking #</th>
                                <th>Ship Date</th>
                                <th>Status</th>
                                <th>Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($shipping as $shipment): ?>
                                <tr>
                                    <td><?php echo $shipment['id']; ?></td>
                                    <td><?php echo $shipment['order_id']; ?></td>
                                    <td><?php echo strtoupper($shipment['carrier']); ?></td>
                                    <td><?php echo htmlspecialchars($shipment['tracking_number']); ?></td>
                                    <td><?php echo date('M j, Y', strtotime($shipment['ship_date'])); ?></td>
                                    <td>
                                        <span class="status-badge <?php echo $shipment['status']; ?>">
                                            <?php echo ucfirst(str_replace('_', ' ', $shipment['status'])); ?>
                                        </span>
                                    </td>
                                    <td class="actions">
                                        <a href="index.php?tab=shipping&edit=shipping&id=<?php echo $shipment['id']; ?>" class="btn-secondary">Edit</a>
                                        <a href="index.php?tab=shipping&delete=shipping&id=<?php echo $shipment['id']; ?>" class="btn-danger" onclick="return confirm('Are you sure you want to delete this shipment record?')">Delete</a>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>
</div>

<script>
    // Search functionality
    document.getElementById('customer-search')?.addEventListener('input', function(e) {
        const searchTerm = e.target.value.toLowerCase();
        const rows = document.querySelectorAll('#customer-table tbody tr');
        
        rows.forEach(row => {
            const name = row.cells[1].textContent.toLowerCase();
            const email = row.cells[2].textContent.toLowerCase();
            if (name.includes(searchTerm) || email.includes(searchTerm)) {
                row.style.display = '';
            } else {
                row.style.display = 'none';
            }
        });
    });

    document.getElementById('product-search')?.addEventListener('input', function(e) {
        const searchTerm = e.target.value.toLowerCase();
        const rows = document.querySelectorAll('#product-table tbody tr');
        
        rows.forEach(row => {
            const name = row.cells[1].textContent.toLowerCase();
            const category = row.cells[4].textContent.toLowerCase();
            if (name.includes(searchTerm) || category.includes(searchTerm)) {
                row.style.display = '';
            } else {
                row.style.display = 'none';
            }
        });
    });

    document.getElementById('order-search')?.addEventListener('input', function(e) {
        const searchTerm = e.target.value.toLowerCase();
        const rows = document.querySelectorAll('#order-table tbody tr');
        
        rows.forEach(row => {
            const customer = row.cells[1].textContent.toLowerCase();
            const product = row.cells[2].textContent.toLowerCase();
            if (customer.includes(searchTerm) || product.includes(searchTerm)) {
                row.style.display = '';
            } else {
                row.style.display = 'none';
            }
        });
    });

    document.getElementById('payment-search')?.addEventListener('input', function(e) {
        const searchTerm = e.target.value.toLowerCase();
        const rows = document.querySelectorAll('#payment-table tbody tr');
        
        rows.forEach(row => {
            const orderId = row.cells[1].textContent.toLowerCase();
            const method = row.cells[3].textContent.toLowerCase();
            if (orderId.includes(searchTerm) || method.includes(searchTerm)) {
                row.style.display = '';
            } else {
                row.style.display = 'none';
            }
        });
    });

    document.getElementById('shipping-search')?.addEventListener('input', function(e) {
        const searchTerm = e.target.value.toLowerCase();
        const rows = document.querySelectorAll('#shipping-table tbody tr');
        
        rows.forEach(row => {
            const orderId = row.cells[1].textContent.toLowerCase();
            const tracking = row.cells[3].textContent.toLowerCase();
            if (orderId.includes(searchTerm) || tracking.includes(searchTerm)) {
                row.style.display = '';
            } else {
                row.style.display = 'none';
            }
        });
    });

    // Auto-hide notification after 3 seconds
    const notification = document.querySelector('.notification');
    if (notification) {
        setTimeout(() => {
            notification.style.display = 'none';
        }, 3000);
    }

    // Calculate order total when product or quantity changes
    const orderProductSelect = document.getElementById('order-product');
    const orderQuantityInput = document.getElementById('order-quantity');
    const paymentAmountInput = document.getElementById('payment-amount');

    if (orderProductSelect && orderQuantityInput) {
        orderProductSelect.addEventListener('change', updateOrderTotal);
        orderQuantityInput.addEventListener('input', updateOrderTotal);
    }

    function updateOrderTotal() {
        if (orderProductSelect && orderQuantityInput && paymentAmountInput) {
            const productOption = orderProductSelect.options[orderProductSelect.selectedIndex];
            const priceMatch = productOption.text.match(/\(([^)]+)\)/);
            if (priceMatch) {
                const price = parseFloat(priceMatch[1].replace('$', ''));
                const quantity = parseInt(orderQuantityInput.value) || 0;
                const total = price * quantity;
                paymentAmountInput.value = total.toFixed(2);
            }
        }
    }
</script>
</body>
</html>