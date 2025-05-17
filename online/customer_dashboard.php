<?php
session_start();
require_once 'config.php';

// Get current tab
$current_tab = isset($_GET['tab']) ? $_GET['tab'] : 'customers';

// Handle form submissions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if ($current_tab === 'customers') {
        handleCustomerForm($db);
    } elseif ($current_tab === 'orders') {
        handleOrderForm($db);
    } elseif ($current_tab === 'payments') {
        $customer_id = isset($_POST['customer_id']) ? intval($_POST['customer_id']) : 0;
        handlePaymentForm($db, $customer_id);
    } elseif ($current_tab === 'shipping') {
        $customer_id = isset($_POST['customer_id']) ? intval($_POST['customer_id']) : 0;
        handleShippingForm($db, $customer_id);
    }
}

// Customer form handler
function handleCustomerForm($db) {
    $name = trim($_POST['name']);
    $email = trim($_POST['email']);
    $phone = trim($_POST['phone']);
    $address = trim($_POST['address']);

    try {
        // Check if email already exists
        $stmt = $db->prepare("SELECT id FROM customers WHERE email = ?");
        $stmt->execute([$email]);
        if ($stmt->fetch()) {
            throw new Exception("Customer with this email already exists");
        }

        // Create new customer
        $stmt = $db->prepare("INSERT INTO customers (name, email, phone, address, created_at) 
                             VALUES (?, ?, ?, ?, ?)");
        $created_at = date('Y-m-d H:i:s');
        $stmt->execute([$name, $email, $phone, $address, $created_at]);
        
        // Update the active customers count in session to reflect immediately
        $stmt = $db->query("SELECT COUNT(*) FROM customers");
        $_SESSION['active_customers'] = $stmt->fetchColumn();
        
        showNotification("Customer added successfully!");
    } catch(Exception $e) {
        showNotification("Error adding customer: " . $e->getMessage(), 'error');
    }
    redirect("customer_dashboard.php?tab=customers");
}

// Order form handler
function handleOrderForm($db) {
    $customer_id = intval($_POST['customer_id']);
    $product_id = intval($_POST['product_id']);
    $quantity = intval($_POST['quantity']);

    try {
        // Get product price and stock
        $stmt = $db->prepare("SELECT price, stock FROM products WHERE id = ?");
        $stmt->execute([$product_id]);
        $product = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$product) {
            throw new Exception("Product not found");
        }

        if ($product['stock'] < $quantity) {
            throw new Exception("Not enough stock available");
        }

        // Create new order
        $db->beginTransaction();
        
        $stmt = $db->prepare("INSERT INTO orders (customer_id, order_date, status) VALUES (?, ?, ?)");
        $order_date = date('Y-m-d');
        $status = 'pending';
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
    } catch(Exception $e) {
        $db->rollBack();
        showNotification("Error processing order: " . $e->getMessage(), 'error');
    }
    redirect("customer_dashboard.php?tab=orders");
}

// Payment form handler
function handlePaymentForm($db, $customer_id) {
    $order_id = intval($_POST['order_id']);
    $amount = floatval($_POST['amount']);
    $method = trim($_POST['method']);
    $status = 'completed'; // Default status

    try {
        $db->beginTransaction();
        
        // Verify the order belongs to this customer
        $stmt = $db->prepare("SELECT id FROM orders WHERE id = ? AND customer_id = ?");
        $stmt->execute([$order_id, $customer_id]);
        if (!$stmt->fetch()) {
            throw new Exception("Order not found or doesn't belong to you");
        }
        
        // Create payment record
        $stmt = $db->prepare("INSERT INTO payments (order_id, amount, method, payment_date, status) 
                             VALUES (?, ?, ?, ?, ?)");
        $payment_date = date('Y-m-d');
        $stmt->execute([$order_id, $amount, $method, $payment_date, $status]);
        
        // Update order status to paid
        $stmt = $db->prepare("UPDATE orders SET status = 'processing' WHERE id = ?");
        $stmt->execute([$order_id]);
        
        $db->commit();
        showNotification("Payment processed successfully!");
    } catch(Exception $e) {
        $db->rollBack();
        showNotification("Error processing payment: " . $e->getMessage(), 'error');
    }
    redirect("customer_dashboard.php?tab=payments");
}

// Shipping form handler
function handleShippingForm($db, $customer_id) {
    $order_id = intval($_POST['order_id']);
    $carrier = trim($_POST['carrier']);
    $tracking_number = trim($_POST['tracking_number']);
    $status = 'in_transit'; // Default status

    try {
        $db->beginTransaction();
        
        // Verify the order belongs to this customer and is paid
        $stmt = $db->prepare("SELECT id FROM orders WHERE id = ? AND customer_id = ? AND status = 'processing'");
        $stmt->execute([$order_id, $customer_id]);
        if (!$stmt->fetch()) {
            throw new Exception("Order not found, doesn't belong to you, or isn't ready for shipping");
        }
        
        // Create shipping record
        $stmt = $db->prepare("INSERT INTO shipping (order_id, carrier, tracking_number, ship_date, status) 
                             VALUES (?, ?, ?, ?, ?)");
        $ship_date = date('Y-m-d');
        $stmt->execute([$order_id, $carrier, $tracking_number, $ship_date, $status]);
        
        // Update order status to shipped
        $stmt = $db->prepare("UPDATE orders SET status = 'shipped' WHERE id = ?");
        $stmt->execute([$order_id]);
        
        $db->commit();
        showNotification("Shipping information added successfully!");
    } catch(Exception $e) {
        $db->rollBack();
        showNotification("Error adding shipping info: " . $e->getMessage(), 'error');
    }
    redirect("customer_dashboard.php?tab=shipping");
}

// Get statistics
$stats = [];
try {
    // Total Customers
    $stats['total_customers'] = isset($_SESSION['active_customers']) ? 
        $_SESSION['active_customers'] : 
        $db->query("SELECT COUNT(*) FROM customers")->fetchColumn();

    // Total Orders
    $stmt = $db->query("SELECT COUNT(*) as total_orders FROM orders");
    $stats['total_orders'] = $stmt->fetchColumn();

    // Total Revenue
    $stmt = $db->query("SELECT SUM(oi.quantity * oi.price) as total_spent 
                        FROM order_items oi 
                        JOIN orders o ON oi.order_id = o.id");
    $stats['total_spent'] = $stmt->fetchColumn() ?: 0;

    // Pending Orders
    $stmt = $db->query("SELECT COUNT(*) as pending_orders FROM orders WHERE status = 'pending'");
    $stats['pending_orders'] = $stmt->fetchColumn();

    // Processing Orders
    $stmt = $db->query("SELECT COUNT(*) as processing_orders FROM orders WHERE status = 'processing'");
    $stats['processing_orders'] = $stmt->fetchColumn();

    // Completed Orders
    $stmt = $db->query("SELECT COUNT(*) as completed_orders FROM orders WHERE status = 'completed'");
    $stats['completed_orders'] = $stmt->fetchColumn();
} catch(PDOException $e) {
    showNotification("Error fetching statistics: " . $e->getMessage(), 'error');
}

// Get all customers
$customers = [];
try {
    $stmt = $db->query("SELECT * FROM customers ORDER BY created_at DESC");
    $customers = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch(PDOException $e) {
    showNotification("Error fetching customers: " . $e->getMessage(), 'error');
}

// Get data for current tab
$products = getAllRecords($db, 'products', 'name');
$all_orders = getAllRecords($db, "orders", "order_date DESC");

// Get orders with product details
$orders = [];
try {
    $stmt = $db->prepare("
        SELECT o.id, o.customer_id, o.order_date, o.status, 
               c.name as customer_name,
               p.name as product_name, oi.quantity, oi.price
        FROM orders o
        JOIN order_items oi ON o.id = oi.order_id
        JOIN products p ON oi.product_id = p.id
        JOIN customers c ON o.customer_id = c.id
        ORDER BY o.order_date DESC
    ");
    $stmt->execute();
    $orders = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch(PDOException $e) {
    showNotification("Error fetching orders: " . $e->getMessage(), 'error');
}

// Get payments
$payments = [];
try {
    $stmt = $db->prepare("
        SELECT p.*, c.name as customer_name
        FROM payments p
        JOIN orders o ON p.order_id = o.id
        JOIN customers c ON o.customer_id = c.id
        ORDER BY p.payment_date DESC
    ");
    $stmt->execute();
    $payments = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch(PDOException $e) {
    showNotification("Error fetching payments: " . $e->getMessage(), 'error');
}

// Get shipping
$shipping = [];
try {
    $stmt = $db->prepare("
        SELECT s.*, c.name as customer_name
        FROM shipping s
        JOIN orders o ON s.order_id = o.id
        JOIN customers c ON o.customer_id = c.id
        ORDER BY s.ship_date DESC
    ");
    $stmt->execute();
    $shipping = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch(PDOException $e) {
    showNotification("Error fetching shipping: " . $e->getMessage(), 'error');
}

// Check for notification
$notification = isset($_SESSION['notification']) ? $_SESSION['notification'] : null;
unset($_SESSION['notification']);
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Customer Dashboard - Order Processing System</title>
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0-beta3/css/all.min.css">
    <style>
        :root {
            --primary: #4361ee;
            --secondary: #3f37c9;
            --accent: #4895ef;
            --danger: #f72585;
            --success: #4cc9f0;
            --warning: #f8961e;
            --info: #560bad;
            --dark: #212529;
            --light: #f8f9fa;
            --gray: #6c757d;
            --white: #ffffff;
            
            --shadow-sm: 0 1px 2px rgba(0,0,0,0.1);
            --shadow: 0 4px 6px rgba(0,0,0,0.1);
            --shadow-md: 0 10px 15px rgba(0,0,0,0.1);
            --shadow-lg: 0 20px 25px rgba(0,0,0,0.1);
            
            --rounded-sm: 0.2rem;
            --rounded: 0.4rem;
            --rounded-md: 0.6rem;
            --rounded-lg: 0.8rem;
            --rounded-full: 9999px;
            
            --transition: all 0.3s ease;
        }

        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }

        body {
            font-family: 'Poppins', sans-serif;
            background-color: #f5f7fa;
            color: var(--dark);
            line-height: 1.6;
        }

        .container {
            max-width: 1200px;
            margin: 0 auto;
            padding: 20px;
        }

        header {
            background: linear-gradient(135deg, var(--primary), var(--secondary));
            color: var(--white);
            padding: 2rem 0;
            text-align: center;
            margin-bottom: 2rem;
            border-radius: var(--rounded);
            box-shadow: var(--shadow);
            position: relative;
            overflow: hidden;
        }

        header::before {
            content: '';
            position: absolute;
            top: -50%;
            left: -50%;
            width: 200%;
            height: 200%;
            background: radial-gradient(circle, rgba(255,255,255,0.1) 0%, rgba(255,255,255,0) 70%);
            transform: rotate(30deg);
        }

        h1 {
            font-size: 2.5rem;
            margin-bottom: 0.5rem;
            font-weight: 600;
            position: relative;
        }

        .subtitle {
            font-size: 1.1rem;
            opacity: 0.9;
            font-weight: 300;
            position: relative;
        }

        /* Statistics Grid */
        .stats-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(240px, 1fr));
            gap: 1.5rem;
            margin-bottom: 2rem;
        }

        .stat-card {
            background: var(--white);
            border-radius: var(--rounded);
            padding: 1.5rem;
            box-shadow: var(--shadow);
            display: flex;
            align-items: center;
            transition: var(--transition);
        }

        .stat-card:hover {
            transform: translateY(-5px);
            box-shadow: var(--shadow-md);
        }

        .stat-icon {
            width: 60px;
            height: 60px;
            background: rgba(67, 97, 238, 0.1);
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            margin-right: 1.5rem;
            color: var(--primary);
            font-size: 1.5rem;
        }

        .stat-info h3 {
            font-size: 0.9rem;
            color: var(--gray);
            margin-bottom: 0.3rem;
            font-weight: 500;
        }

        .stat-value {
            font-size: 1.8rem;
            font-weight: 700;
            color: var(--dark);
        }

        /* Tabs */
        .tab-container {
            margin-bottom: 2rem;
            background: var(--white);
            border-radius: var(--rounded);
            box-shadow: var(--shadow);
            overflow: hidden;
        }

        .tabs {
            display: flex;
            list-style: none;
            background: var(--light);
        }

        .tab {
            padding: 1rem 2rem;
            cursor: pointer;
            font-weight: 500;
            color: var(--gray);
            transition: var(--transition);
            border-bottom: 3px solid transparent;
        }

        .tab:hover {
            color: var(--primary);
            background: rgba(67, 97, 238, 0.05);
        }

        .tab.active {
            color: var(--primary);
            border-bottom-color: var(--primary);
            background: var(--white);
        }

        .tab-content {
            display: none;
            padding: 2rem;
        }

        .tab-content.active {
            display: block;
            animation: fadeIn 0.5s ease-out;
        }

        /* Cards */
        .card {
            background: var(--white);
            border-radius: var(--rounded);
            padding: 2rem;
            box-shadow: var(--shadow);
            margin-bottom: 2rem;
        }

        .card h2 {
            color: var(--dark);
            margin-bottom: 1.5rem;
            font-size: 1.5rem;
            display: flex;
            align-items: center;
            gap: 0.5rem;
        }

        /* Product Grid */
        .product-grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(280px, 1fr));
            gap: 1.5rem;
        }

        .product-card {
            background: var(--white);
            border-radius: var(--rounded);
            overflow: hidden;
            box-shadow: var(--shadow);
            transition: var(--transition);
            position: relative;
        }

        .product-card:hover {
            transform: translateY(-5px);
            box-shadow: var(--shadow-md);
        }

        .product-badge {
            position: absolute;
            top: 1rem;
            right: 1rem;
            background: var(--danger);
            color: var(--white);
            padding: 0.3rem 0.6rem;
            border-radius: var(--rounded-sm);
            font-size: 0.8rem;
            font-weight: 600;
        }

        .product-image {
            height: 200px;
            background: linear-gradient(45deg, #f5f7fa, #e4e8f0);
            display: flex;
            align-items: center;
            justify-content: center;
            color: var(--gray);
            font-size: 4rem;
        }

        .product-details {
            padding: 1.5rem;
        }

        .product-name {
            font-size: 1.2rem;
            margin-bottom: 0.5rem;
            color: var(--dark);
            font-weight: 600;
        }

        .product-price {
            font-size: 1.4rem;
            font-weight: 700;
            color: var(--primary);
            margin-bottom: 0.5rem;
        }

        .product-stock {
            display: flex;
            align-items: center;
            font-size: 0.9rem;
            color: var(--gray);
            margin-bottom: 1rem;
        }

        .product-stock .stock-bar {
            flex-grow: 1;
            height: 6px;
            background: #e9ecef;
            border-radius: 3px;
            margin-left: 0.5rem;
            overflow: hidden;
        }

        .product-stock .stock-progress {
            height: 100%;
            background: var(--success);
        }

        .product-category {
            display: inline-block;
            background: rgba(67, 97, 238, 0.1);
            color: var(--primary);
            padding: 0.3rem 0.8rem;
            border-radius: var(--rounded-full);
            font-size: 0.8rem;
            font-weight: 500;
            margin-bottom: 1rem;
        }

        /* Forms */
        .form-group {
            margin-bottom: 1rem;
        }

        .form-group label {
            display: block;
            margin-bottom: 0.5rem;
            font-weight: 500;
            color: var(--dark);
        }

        .form-control {
            width: 100%;
            padding: 0.6rem 1rem;
            border: 1px solid #ddd;
            border-radius: var(--rounded-sm);
            font-size: 1rem;
            transition: var(--transition);
        }

        .form-control:focus {
            outline: none;
            border-color: var(--primary);
            box-shadow: 0 0 0 3px rgba(67, 97, 238, 0.2);
        }

        /* Buttons */
        .btn {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            padding: 0.6rem 1.2rem;
            border-radius: var(--rounded);
            font-weight: 500;
            cursor: pointer;
            transition: var(--transition);
            border: none;
            font-size: 0.95rem;
        }

        .btn i {
            margin-right: 0.5rem;
        }

        .btn-primary {
            background: var(--primary);
            color: var(--white);
            flex-grow: 1;
        }

        .btn-primary:hover {
            background: var(--secondary);
            transform: translateY(-2px);
            box-shadow: var(--shadow);
        }

        /* Tables */
        table {
            width: 100%;
            border-collapse: collapse;
            margin-top: 1rem;
            background: var(--white);
            border-radius: var(--rounded);
            overflow: hidden;
            box-shadow: var(--shadow);
        }

        th, td {
            padding: 1rem;
            text-align: left;
            border-bottom: 1px solid #eee;
        }

        th {
            background: var(--primary);
            color: var(--white);
            font-weight: 500;
        }

        tr:nth-child(even) {
            background: rgba(0,0,0,0.02);
        }

        tr:hover {
            background: rgba(67, 97, 238, 0.05);
        }

        /* Status Badges */
        .status-badge {
            display: inline-flex;
            align-items: center;
            gap: 0.3rem;
            padding: 0.3rem 0.8rem;
            border-radius: var(--rounded-full);
            font-size: 0.8rem;
            font-weight: 600;
            text-transform: capitalize;
        }

        .status-badge.pending {
            background: rgba(248, 150, 30, 0.1);
            color: var(--warning);
        }

        .status-badge.processing {
            background: rgba(67, 97, 238, 0.1);
            color: var(--primary);
        }

        .status-badge.completed {
            background: rgba(76, 201, 240, 0.1);
            color: var(--success);
        }

        .status-badge.shipped {
            background: rgba(134, 38, 206, 0.1);
            color: #8626ce;
        }

        .status-badge.delivered {
            background: rgba(16, 185, 129, 0.1);
            color: #10b981;
        }

        /* Search Bar */
        .search-bar {
            margin-bottom: 1.5rem;
            position: relative;
        }

        .search-bar input {
            width: 100%;
            max-width: 400px;
            padding: 0.6rem 1rem 0.6rem 2.5rem;
            border-radius: var(--rounded-full);
            border: 1px solid #ddd;
            font-size: 0.95rem;
            transition: var(--transition);
        }

        .search-bar input:focus {
            border-color: var(--primary);
            box-shadow: 0 0 0 3px rgba(67, 97, 238, 0.2);
        }

        .search-bar i {
            position: absolute;
            left: 1rem;
            top: 50%;
            transform: translateY(-50%);
            color: var(--gray);
        }

        /* Notification */
        .notification {
            position: fixed;
            top: 20px;
            right: 20px;
            padding: 1rem 1.5rem;
            color: var(--white);
            border-radius: var(--rounded);
            box-shadow: var(--shadow-lg);
            transform: translateX(200%);
            transition: transform 0.3s ease;
            z-index: 1000;
            display: flex;
            align-items: center;
        }

        .notification.show {
            transform: translateX(0);
        }

        .notification.success {
            background: var(--success);
        }

        .notification.error {
            background: var(--danger);
        }

        .notification i {
            margin-right: 0.5rem;
            font-size: 1.2rem;
        }

        /* Responsive */
        @media (max-width: 768px) {
            .stats-grid {
                grid-template-columns: 1fr 1fr;
            }
            
            .tabs {
                flex-wrap: wrap;
            }
            
            .tab {
                flex: 1;
                min-width: 120px;
                text-align: center;
                padding: 0.8rem;
            }
            
            .product-grid {
                grid-template-columns: 1fr;
            }
            
            header {
                padding: 1.5rem 0;
            }
            
            h1 {
                font-size: 2rem;
            }
        }

        @keyframes fadeIn {
            from { opacity: 0; transform: translateY(10px); }
            to { opacity: 1; transform: translateY(0); }
        }
        
        .customer-card {
            background: var(--white);
            border-radius: var(--rounded);
            padding: 1.5rem;
            box-shadow: var(--shadow);
            margin-bottom: 1.5rem;
            transition: var(--transition);
        }
        
        .customer-card:hover {
            transform: translateY(-3px);
            box-shadow: var(--shadow-md);
        }
        
        .customer-header {
            display: flex;
            align-items: center;
            margin-bottom: 1rem;
        }
        
        .customer-avatar {
            width: 50px;
            height: 50px;
            background: linear-gradient(135deg, var(--primary), var(--secondary));
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            color: var(--white);
            font-weight: bold;
            font-size: 1.2rem;
            margin-right: 1rem;
        }
        
        .customer-info h3 {
            font-size: 1.2rem;
            margin-bottom: 0.2rem;
            color: var(--dark);
        }
        
        .customer-info p {
            color: var(--gray);
            font-size: 0.9rem;
        }
        
        .customer-details {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(200px, 1fr));
            gap: 1rem;
        }
        
        .detail-item {
            display: flex;
            align-items: center;
        }
        
        .detail-item i {
            margin-right: 0.5rem;
            color: var(--primary);
        }
    </style>
</head>
<body>
    <div class="container">
        <header>
            <h1><i class="fas fa-users-cog"></i> Customer Dashboard</h1>
            <p class="subtitle">Manage customers, orders, and products</p>
        </header>

        <!-- Statistics Cards -->
        <div class="stats-grid">
            <div class="stat-card">
                <div class="stat-icon">
                    <i class="fas fa-users"></i>
                </div>
                <div class="stat-info">
                    <h3>Total Customers</h3>
                    <div class="stat-value"><?php echo $stats['total_customers']; ?></div>
                </div>
            </div>
            <div class="stat-card">
                <div class="stat-icon">
                    <i class="fas fa-clipboard-list"></i>
                </div>
                <div class="stat-info">
                    <h3>Total Orders</h3>
                    <div class="stat-value"><?php echo $stats['total_orders']; ?></div>
                </div>
            </div>
            <div class="stat-card">
                <div class="stat-icon">
                    <i class="fas fa-dollar-sign"></i>
                </div>
                <div class="stat-info">
                    <h3>Total Revenue</h3>
                    <div class="stat-value">$<?php echo number_format($stats['total_spent'], 2); ?></div>
                </div>
            </div>
            <div class="stat-card">
                <div class="stat-icon">
                    <i class="fas fa-tasks"></i>
                </div>
                <div class="stat-info">
                    <h3>Order Status</h3>
                    <div class="stat-value">
                        <span class="status-badge pending"><?php echo $stats['pending_orders']; ?> Pending</span>
                        <span class="status-badge processing"><?php echo $stats['processing_orders']; ?> Processing</span>
                        <span class="status-badge completed"><?php echo $stats['completed_orders']; ?> Completed</span>
                    </div>
                </div>
            </div>
        </div>

        <?php if ($notification): ?>
            <div class="notification show <?php echo $notification['type']; ?>">
                <i class="fas <?php echo $notification['type'] === 'success' ? 'fa-check-circle' : 'fa-exclamation-circle'; ?>"></i>
                <?php echo $notification['message']; ?>
            </div>
        <?php endif; ?>

        <div class="tab-container">
            <ul class="tabs">
                <li class="tab <?php echo $current_tab === 'customers' ? 'active' : ''; ?>">
                    <a href="customer_dashboard.php?tab=customers"><i class="fas fa-users"></i> Customers</a>
                </li>
                <li class="tab <?php echo $current_tab === 'products' ? 'active' : ''; ?>">
                    <a href="customer_dashboard.php?tab=products"><i class="fas fa-box-open"></i> Products</a>
                </li>
                <li class="tab <?php echo $current_tab === 'orders' ? 'active' : ''; ?>">
                    <a href="customer_dashboard.php?tab=orders"><i class="fas fa-clipboard-list"></i> Orders</a>
                </li>
                <li class="tab <?php echo $current_tab === 'payments' ? 'active' : ''; ?>">
                    <a href="customer_dashboard.php?tab=payments"><i class="fas fa-credit-card"></i> Payments</a>
                </li>
                <li class="tab <?php echo $current_tab === 'shipping' ? 'active' : ''; ?>">
                    <a href="customer_dashboard.php?tab=shipping"><i class="fas fa-truck"></i> Shipping</a>
                </li>
            </ul>

            <!-- Customers Tab -->
            <div id="customers" class="tab-content <?php echo $current_tab === 'customers' ? 'active' : ''; ?>">
                <div class="card">
                    <h2><i class="fas fa-user-plus"></i> Add New Customer</h2>
                    <form method="POST" action="customer_dashboard.php?tab=customers">
                        <div class="form-group">
                            <label for="customer-name">Full Name</label>
                            <input type="text" id="customer-name" name="name" class="form-control" required>
                        </div>
                        <div class="form-group">
                            <label for="customer-email">Email Address</label>
                            <input type="email" id="customer-email" name="email" class="form-control" required>
                        </div>
                        <div class="form-group">
                            <label for="customer-phone">Phone Number</label>
                            <input type="tel" id="customer-phone" name="phone" class="form-control" required>
                        </div>
                        <div class="form-group">
                            <label for="customer-address">Address</label>
                            <textarea id="customer-address" name="address" class="form-control" rows="3" required></textarea>
                        </div>
                        <button type="submit" class="btn btn-primary">
                            <i class="fas fa-save"></i> Save Customer
                        </button>
                    </form>
                </div>

                <div class="card">
                    <h2><i class="fas fa-users"></i> Customer List</h2>
                    <div class="search-bar">
                        <i class="fas fa-search"></i>
                        <input type="text" id="customer-search" placeholder="Search customers...">
                    </div>
                    
                    <?php foreach ($customers as $customer): ?>
                        <div class="customer-card">
                            <div class="customer-header">
                                <div class="customer-avatar">
                                    <?php echo strtoupper(substr($customer['name'], 0, 1)); ?>
                                </div>
                                <div class="customer-info">
                                    <h3><?php echo htmlspecialchars($customer['name']); ?></h3>
                                    <p>Joined <?php echo date('M j, Y', strtotime($customer['created_at'])); ?></p>
                                </div>
                            </div>
                            <div class="customer-details">
                                <div class="detail-item">
                                    <i class="fas fa-envelope"></i>
                                    <span><?php echo htmlspecialchars($customer['email']); ?></span>
                                </div>
                                <div class="detail-item">
                                    <i class="fas fa-phone"></i>
                                    <span><?php echo htmlspecialchars($customer['phone']); ?></span>
                                </div>
                                <div class="detail-item">
                                    <i class="fas fa-map-marker-alt"></i>
                                    <span><?php echo htmlspecialchars($customer['address']); ?></span>
                                </div>
                                <div class="detail-item">
                                    <i class="fas fa-shopping-cart"></i>
                                    <span>
                                        <?php 
                                            $stmt = $db->prepare("SELECT COUNT(*) FROM orders WHERE customer_id = ?");
                                            $stmt->execute([$customer['id']]);
                                            echo $stmt->fetchColumn() . " orders";
                                        ?>
                                    </span>
                                </div>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            </div>

            <!-- Products Tab -->
            <div id="products" class="tab-content <?php echo $current_tab === 'products' ? 'active' : ''; ?>">
                <div class="search-bar">
                    <i class="fas fa-search"></i>
                    <input type="text" id="product-search" placeholder="Search products...">
                </div>
                <div class="product-grid">
                    <?php foreach ($products as $product): 
                        $stock_percentage = ($product['stock'] / 100) * 100;
                        $stock_class = $stock_percentage > 50 ? 'high' : ($stock_percentage > 20 ? 'medium' : 'low');
                    ?>
                        <div class="product-card">
                            <?php if($product['stock'] < 10): ?>
                                <span class="product-badge">Low Stock</span>
                            <?php endif; ?>
                            <div class="product-image">
                                <i class="fas fa-<?php echo $product['category'] === 'electronics' ? 'laptop' : ($product['category'] === 'clothing' ? 'tshirt' : 'box'); ?>"></i>
                            </div>
                            <div class="product-details">
                                <h3 class="product-name"><?php echo htmlspecialchars($product['name']); ?></h3>
                                <div class="product-price">$<?php echo number_format($product['price'], 2); ?></div>
                                <div class="product-stock">
                                    <span>Stock: <?php echo $product['stock']; ?></span>
                                    <div class="stock-bar">
                                        <div class="stock-progress" style="width: <?php echo $stock_percentage; ?>%"></div>
                                    </div>
                                </div>
                                <span class="product-category"><?php echo ucfirst($product['category']); ?></span>
                                <form method="POST" action="customer_dashboard.php?tab=orders">
                                    <input type="hidden" name="product_id" value="<?php echo $product['id']; ?>">
                                    <div class="form-group">
                                        <label for="quantity-<?php echo $product['id']; ?>">Quantity</label>
                                        <input type="number" class="form-control" id="quantity-<?php echo $product['id']; ?>" 
                                               name="quantity" min="1" max="<?php echo $product['stock']; ?>" value="1">
                                    </div>
                                    <div class="form-group">
                                        <label for="customer-select-<?php echo $product['id']; ?>">Customer</label>
                                        <select id="customer-select-<?php echo $product['id']; ?>" name="customer_id" class="form-control" required>
                                            <option value="">Select Customer</option>
                                            <?php foreach ($customers as $customer): ?>
                                                <option value="<?php echo $customer['id']; ?>"><?php echo htmlspecialchars($customer['name']); ?></option>
                                            <?php endforeach; ?>
                                        </select>
                                    </div>
                                    <button type="submit" class="btn btn-primary">
                                        <i class="fas fa-cart-plus"></i> Order Now
                                    </button>
                                </form>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            </div>

            <!-- Orders Tab -->
            <div id="orders" class="tab-content <?php echo $current_tab === 'orders' ? 'active' : ''; ?>">
                <div class="search-bar">
                    <i class="fas fa-search"></i>
                    <input type="text" id="order-search" placeholder="Search orders...">
                </div>
                <table>
                    <thead>
                        <tr>
                            <th>Order ID</th>
                            <th>Customer</th>
                            <th>Product</th>
                            <th>Quantity</th>
                            <th>Total</th>
                            <th>Date</th>
                            <th>Status</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($orders as $order): ?>
                            <tr>
                                <td>#<?php echo $order['id']; ?></td>
                                <td><?php echo htmlspecialchars($order['customer_name']); ?></td>
                                <td><?php echo htmlspecialchars($order['product_name']); ?></td>
                                <td><?php echo $order['quantity']; ?></td>
                                <td>$<?php echo number_format($order['quantity'] * $order['price'], 2); ?></td>
                                <td><?php echo date('M j, Y', strtotime($order['order_date'])); ?></td>
                                <td>
                                    <span class="status-badge <?php echo $order['status']; ?>">
                                        <i class="fas fa-<?php echo $order['status'] === 'pending' ? 'clock' : ($order['status'] === 'processing' ? 'cog' : ($order['status'] === 'completed' ? 'check' : ($order['status'] === 'shipped' ? 'shipping-fast' : 'truck'))); ?>"></i>
                                        <?php echo ucfirst($order['status']); ?>
                                    </span>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>

            <!-- Payments Tab -->
            <div id="payments" class="tab-content <?php echo $current_tab === 'payments' ? 'active' : ''; ?>">
                <div class="card">
                    <h2><i class="fas fa-credit-card"></i> Make Payment</h2>
                    <form method="POST" action="customer_dashboard.php?tab=payments">
                        <div class="form-group">
                            <label for="payment-customer-id">Customer</label>
                            <select id="payment-customer-id" name="customer_id" class="form-control" required>
                                <option value="">Select Customer</option>
                                <?php foreach ($customers as $customer): ?>
                                    <option value="<?php echo $customer['id']; ?>"><?php echo htmlspecialchars($customer['name']); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="form-group">
                            <label for="payment-order-id">Order ID</label>
                            <select id="payment-order-id" name="order_id" class="form-control" required>
                                <option value="">Select Order</option>
                                <?php foreach ($all_orders as $order): ?>
                                    <?php if ($order['status'] === 'pending'): ?>
                                        <option value="<?php echo $order['id']; ?>" data-customer="<?php echo $order['customer_id']; ?>">
                                            Order #<?php echo $order['id']; ?> - $<?php 
                                                $stmt = $db->prepare("SELECT SUM(oi.quantity * oi.price) as total 
                                                                    FROM order_items oi 
                                                                    WHERE oi.order_id = ?");
                                                $stmt->execute([$order['id']]);
                                                $total = $stmt->fetchColumn();
                                                echo number_format($total, 2);
                                            ?>
                                        </option>
                                    <?php endif; ?>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="form-group">
                            <label for="payment-amount">Amount</label>
                            <input type="number" step="0.01" id="payment-amount" name="amount" 
                                   class="form-control" required>
                        </div>
                        <div class="form-group">
                            <label for="payment-method">Payment Method</label>
                            <select id="payment-method" name="method" class="form-control" required>
                                <option value="credit_card">Credit Card</option>
                                <option value="paypal">PayPal</option>
                                <option value="bank_transfer">Bank Transfer</option>
                            </select>
                        </div>
                        <button type="submit" class="btn btn-primary">
                            <i class="fas fa-money-bill-wave"></i> Process Payment
                        </button>
                    </form>
                </div>

                <div class="card">
                    <h2><i class="fas fa-history"></i> Payment History</h2>
                    <table>
                        <thead>
                            <tr>
                                <th>Payment ID</th>
                                <th>Customer</th>
                                <th>Order ID</th>
                                <th>Amount</th>
                                <th>Method</th>
                                <th>Date</th>
                                <th>Status</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($payments as $payment): ?>
                                <tr>
                                    <td>#<?php echo $payment['id']; ?></td>
                                    <td><?php echo htmlspecialchars($payment['customer_name']); ?></td>
                                    <td>#<?php echo $payment['order_id']; ?></td>
                                    <td>$<?php echo number_format($payment['amount'], 2); ?></td>
                                    <td>
                                        <i class="fas fa-<?php echo $payment['method'] === 'credit_card' ? 'credit-card' : ($payment['method'] === 'paypal' ? 'paypal' : 'money-bill-wave'); ?>"></i>
                                        <?php echo ucfirst(str_replace('_', ' ', $payment['method'])); ?>
                                    </td>
                                    <td><?php echo date('M j, Y', strtotime($payment['payment_date'])); ?></td>
                                    <td>
                                        <span class="status-badge <?php echo $payment['status']; ?>">
                                            <?php echo ucfirst($payment['status']); ?>
                                        </span>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>

            <!-- Shipping Tab -->
            <div id="shipping" class="tab-content <?php echo $current_tab === 'shipping' ? 'active' : ''; ?>">
                <div class="card">
                    <h2><i class="fas fa-truck"></i> Add Shipping Information</h2>
                    <form method="POST" action="customer_dashboard.php?tab=shipping">
                        <div class="form-group">
                            <label for="shipping-customer-id">Customer</label>
                            <select id="shipping-customer-id" name="customer_id" class="form-control" required>
                                <option value="">Select Customer</option>
                                <?php foreach ($customers as $customer): ?>
                                    <option value="<?php echo $customer['id']; ?>"><?php echo htmlspecialchars($customer['name']); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="form-group">
                            <label for="shipping-order-id">Order ID</label>
                            <select id="shipping-order-id" name="order_id" class="form-control" required>
                                <option value="">Select Order</option>
                                <?php foreach ($all_orders as $order): ?>
                                    <?php if ($order['status'] === 'processing'): ?>
                                        <option value="<?php echo $order['id']; ?>" data-customer="<?php echo $order['customer_id']; ?>">
                                            Order #<?php echo $order['id']; ?> (<?php echo ucfirst($order['status']); ?>)
                                        </option>
                                    <?php endif; ?>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="form-group">
                            <label for="shipping-carrier">Carrier</label>
                            <select id="shipping-carrier" name="carrier" class="form-control" required>
                                <option value="fedex">FedEx</option>
                                <option value="ups">UPS</option>
                                <option value="usps">USPS</option>
                                <option value="dhl">DHL</option>
                            </select>
                        </div>
                        <div class="form-group">
                            <label for="shipping-tracking">Tracking Number</label>
                            <input type="text" id="shipping-tracking" name="tracking_number" 
                                   class="form-control" required>
                        </div>
                        <button type="submit" class="btn btn-primary">
                            <i class="fas fa-shipping-fast"></i> Submit Shipping Info
                        </button>
                    </form>
                </div>

                <div class="card">
                    <h2><i class="fas fa-map-marked-alt"></i> Shipping History</h2>
                    <table>
                        <thead>
                            <tr>
                                <th>Shipment ID</th>
                                <th>Customer</th>
                                <th>Order ID</th>
                                <th>Carrier</th>
                                <th>Tracking #</th>
                                <th>Date</th>
                                <th>Status</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($shipping as $shipment): ?>
                                <tr>
                                    <td>#<?php echo $shipment['id']; ?></td>
                                    <td><?php echo htmlspecialchars($shipment['customer_name']); ?></td>
                                    <td>#<?php echo $shipment['order_id']; ?></td>
                                    <td>
                                        <i class="fas fa-<?php echo strtolower($shipment['carrier']) === 'fedex' ? 'truck-fast' : (strtolower($shipment['carrier']) === 'ups' ? 'shipping-timed' : 'truck'); ?>"></i>
                                        <?php echo strtoupper($shipment['carrier']); ?>
                                    </td>
                                    <td>
                                        <a href="#" class="text-primary"><?php echo htmlspecialchars($shipment['tracking_number']); ?></a>
                                    </td>
                                    <td><?php echo date('M j, Y', strtotime($shipment['ship_date'])); ?></td>
                                    <td>
                                        <span class="status-badge <?php echo $shipment['status']; ?>">
                                            <?php echo ucfirst(str_replace('_', ' ', $shipment['status'])); ?>
                                        </span>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>

    <script>
        // Update payment amount when order is selected
        document.getElementById('payment-order-id')?.addEventListener('change', function() {
            const orderId = this.value;
            if (orderId) {
                fetch('get_order_total.php?order_id=' + orderId)
                    .then(response => response.json())
                    .then(data => {
                        document.getElementById('payment-amount').value = data.total.toFixed(2);
                    });
            }
        });

        // Filter orders based on selected customer in payment form
        document.getElementById('payment-customer-id')?.addEventListener('change', function() {
            const customerId = this.value;
            const orderSelect = document.getElementById('payment-order-id');
            
            // Enable all options first
            Array.from(orderSelect.options).forEach(option => {
                option.style.display = '';
            });
            
            if (customerId) {
                // Hide options that don't match the selected customer
                Array.from(orderSelect.options).forEach(option => {
                    if (option.value && option.dataset.customer !== customerId) {
                        option.style.display = 'none';
                    }
                });
            }
        });

        // Filter orders based on selected customer in shipping form
        document.getElementById('shipping-customer-id')?.addEventListener('change', function() {
            const customerId = this.value;
            const orderSelect = document.getElementById('shipping-order-id');
            
            // Enable all options first
            Array.from(orderSelect.options).forEach(option => {
                option.style.display = '';
            });
            
            if (customerId) {
                // Hide options that don't match the selected customer
                Array.from(orderSelect.options).forEach(option => {
                    if (option.value && option.dataset.customer !== customerId) {
                        option.style.display = 'none';
                    }
                });
            }
        });

        // Search functionality for customers
        document.getElementById('customer-search')?.addEventListener('input', function(e) {
            const searchTerm = e.target.value.toLowerCase();
            const customerCards = document.querySelectorAll('#customers .customer-card');
            
            customerCards.forEach(card => {
                const name = card.querySelector('.customer-info h3').textContent.toLowerCase();
                const email = card.querySelector('.customer-details .detail-item:nth-child(1) span').textContent.toLowerCase();
                if (name.includes(searchTerm) || email.includes(searchTerm)) {
                    card.style.display = '';
                } else {
                    card.style.display = 'none';
                }
            });
        });

        // Search functionality for products
        document.getElementById('product-search')?.addEventListener('input', function(e) {
            const searchTerm = e.target.value.toLowerCase();
            const productCards = document.querySelectorAll('#products .product-card');
            
            productCards.forEach(card => {
                const name = card.querySelector('.product-name').textContent.toLowerCase();
                const category = card.querySelector('.product-category').textContent.toLowerCase();
                if (name.includes(searchTerm) || category.includes(searchTerm)) {
                    card.style.display = '';
                } else {
                    card.style.display = 'none';
                }
            });
        });

        document.getElementById('order-search')?.addEventListener('input', function(e) {
            const searchTerm = e.target.value.toLowerCase();
            const rows = document.querySelectorAll('#orders tbody tr');
            
            rows.forEach(row => {
                const customer = row.cells[1].textContent.toLowerCase();
                const product = row.cells[2].textContent.toLowerCase();
                const orderId = row.cells[0].textContent.toLowerCase();
                if (customer.includes(searchTerm) || product.includes(searchTerm) || orderId.includes(searchTerm)) {
                    row.style.display = '';
                } else {
                    row.style.display = 'none';
                }
            });
        });

        // Auto-hide notification after 5 seconds
        const notification = document.querySelector('.notification');
        if (notification) {
            setTimeout(() => {
                notification.style.display = 'none';
            }, 5000);
        }

        // Animation for page load
        document.addEventListener('DOMContentLoaded', () => {
            const cards = document.querySelectorAll('.card, .stat-card, .product-card, .customer-card');
            cards.forEach((card, index) => {
                card.style.animationDelay = `${index * 0.1}s`;
            });
        });
    </script>
</body>
</html>