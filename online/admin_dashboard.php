<?php
session_start();
require_once 'config.php';

// Get current tab
$current_tab = isset($_GET['tab']) ? $_GET['tab'] : 'products';

// Get statistics
$stats = [];
try {
    // Total Products
    $stmt = $db->query("SELECT COUNT(*) as total_products FROM products");
    $stats['total_products'] = $stmt->fetchColumn();

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
} catch(PDOException $e) {
    showNotification("Error fetching statistics: " . $e->getMessage(), 'error');
}

// Handle form submissions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if ($current_tab === 'products') {
        handleProductForm($db);
    }
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
    redirect("admin_dashboard.php?tab=products");
}

// Handle record deletions
if (isset($_GET['delete']) && $_GET['delete'] === 'product') {
    $id = intval($_GET['id']);
    
    try {
        $stmt = $db->prepare("DELETE FROM products WHERE id = ?");
        $stmt->execute([$id]);
        showNotification("Product deleted successfully!");
    } catch(PDOException $e) {
        showNotification("Error deleting product: " . $e->getMessage(), 'error');
    }
    redirect("admin_dashboard.php?tab=products");
}

// Get data for current tab
$products = getAllRecords($db, 'products', 'name');

// Get edit record if requested
$edit_record = null;
if (isset($_GET['edit']) && $_GET['edit'] === 'product') {
    $edit_record = getRecordById($db, 'products', $_GET['id']);
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
    <title>Admin Dashboard - Order Processing System</title>
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
            position: relative;
        }

        .logout-btn {
            position: absolute;
            top: 20px;
            right: 20px;
            background-color: var(--danger-color);
            color: white;
            border: none;
            padding: 8px 15px;
            border-radius: 4px;
            cursor: pointer;
            font-size: 0.9rem;
            transition: var(--transition);
        }

        .logout-btn:hover {
            background-color: #c0392b;
        }

        h1 {
            font-size: 2.5rem;
            margin-bottom: 10px;
        }

        .subtitle {
            font-size: 1.2rem;
            opacity: 0.9;
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
            transition: var(--transition);
        }

        .stat-card:hover {
            transform: translateY(-5px);
            box-shadow: 0 10px 20px rgba(0, 0, 0, 0.1);
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

        .product-grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(250px, 1fr));
            gap: 20px;
            margin-top: 20px;
        }

        .product-card {
            background: white;
            border-radius: 8px;
            overflow: hidden;
            box-shadow: var(--shadow);
            transition: var(--transition);
        }

        .product-card:hover {
            transform: translateY(-5px);
            box-shadow: 0 10px 20px rgba(0, 0, 0, 0.1);
        }

        .product-image {
            height: 200px;
            background-color: #f1f1f1;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 3rem;
            color: #ccc;
        }

        .product-details {
            padding: 15px;
        }

        .product-name {
            font-size: 1.2rem;
            margin-bottom: 5px;
            color: var(--dark-color);
        }

        .product-price {
            font-weight: bold;
            color: var(--primary-color);
            margin-bottom: 10px;
        }

        .product-stock {
            color: #666;
            font-size: 0.9rem;
            margin-bottom: 10px;
        }

        .product-category {
            display: inline-block;
            background-color: #f1f1f1;
            padding: 3px 8px;
            border-radius: 20px;
            font-size: 0.8rem;
            color: #666;
        }

        @media (max-width: 768px) {
            .dashboard {
                grid-template-columns: 1fr;
            }
            
            .stats {
                grid-template-columns: 1fr;
            }
            
            .product-grid {
                grid-template-columns: 1fr;
            }
        }

        /* Animation */
        @keyframes fadeIn {
            from { opacity: 0; transform: translateY(20px); }
            to { opacity: 1; transform: translateY(0); }
        }

        .card, .stat-card, .product-card {
            animation: fadeIn 0.5s ease-out forwards;
        }
    </style>
</head>
<body>
    <div class="container">
        <header>
            <h1>Admin Dashboard</h1>
            <p class="subtitle">Manage products and view system statistics</p>
        </header>

        <div class="stats">
            <div class="stat-card">
                <h3>Total Products</h3>
                <div class="stat-value"><?php echo $stats['total_products']; ?></div>
            </div>
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
        </div>

        <?php if ($notification): ?>
            <div class="notification show <?php echo $notification['type']; ?>">
                <?php echo $notification['message']; ?>
            </div>
        <?php endif; ?>

        <div class="dashboard">
            <div class="card">
                <h2><?php echo isset($edit_record) ? 'Edit Product' : 'Add New Product'; ?></h2>
                <form method="POST" action="admin_dashboard.php?tab=products">
                    <?php if (isset($edit_record)): ?>
                        <input type="hidden" name="id" value="<?php echo $edit_record['id']; ?>">
                    <?php endif; ?>
                    <div class="form-group">
                        <label for="product-name">Product Name</label>
                        <input type="text" id="product-name" name="name" required
                               value="<?php echo isset($edit_record) ? htmlspecialchars($edit_record['name']) : ''; ?>">
                    </div>
                    <div class="form-group">
                        <label for="product-description">Description</label>
                        <textarea id="product-description" name="description" rows="3"><?php echo isset($edit_record) ? htmlspecialchars($edit_record['description']) : ''; ?></textarea>
                    </div>
                    <div class="form-group">
                        <label for="product-price">Price</label>
                        <input type="number" id="product-price" name="price" step="0.01" required
                               value="<?php echo isset($edit_record) ? htmlspecialchars($edit_record['price']) : ''; ?>">
                    </div>
                    <div class="form-group">
                        <label for="product-stock">Stock Quantity</label>
                        <input type="number" id="product-stock" name="stock" required
                               value="<?php echo isset($edit_record) ? htmlspecialchars($edit_record['stock']) : ''; ?>">
                    </div>
                    <div class="form-group">
                        <label for="product-category">Category</label>
                        <select id="product-category" name="category">
                            <option value="electronics" <?php echo isset($edit_record) && $edit_record['category'] === 'electronics' ? 'selected' : ''; ?>>Electronics</option>
                            <option value="clothing" <?php echo isset($edit_record) && $edit_record['category'] === 'clothing' ? 'selected' : ''; ?>>Clothing</option>
                            <option value="home" <?php echo isset($edit_record) && $edit_record['category'] === 'home' ? 'selected' : ''; ?>>Home & Garden</option>
                            <option value="books" <?php echo isset($edit_record) && $edit_record['category'] === 'books' ? 'selected' : ''; ?>>Books</option>
                            <option value="other" <?php echo isset($edit_record) && $edit_record['category'] === 'other' ? 'selected' : ''; ?>>Other</option>
                        </select>
                    </div>
                    <button type="submit"><?php echo isset($edit_record) ? 'Update Product' : 'Add Product'; ?></button>
                    <?php if (isset($edit_record)): ?>
                        <a href="admin_dashboard.php?tab=products" class="btn-secondary" style="text-decoration: none; display: inline-block; text-align: center;">Cancel</a>
                    <?php endif; ?>
                </form>
            </div>
            <div class="card">
                <h2>Product Inventory</h2>
                <div class="search-bar">
                    <input type="text" id="product-search" placeholder="Search products...">
                </div>
                <div class="product-grid" id="product-grid">
                    <?php foreach ($products as $product): ?>
                        <div class="product-card">
                            <div class="product-image">
                                <?php echo substr($product['name'], 0, 1); ?>
                            </div>
                            <div class="product-details">
                                <h3 class="product-name"><?php echo htmlspecialchars($product['name']); ?></h3>
                                <div class="product-price">$<?php echo number_format($product['price'], 2); ?></div>
                                <div class="product-stock">Stock: <?php echo $product['stock']; ?></div>
                                <span class="product-category"><?php echo ucfirst($product['category']); ?></span>
                                <div class="actions" style="margin-top: 10px;">
                                    <a href="admin_dashboard.php?tab=products&edit=product&id=<?php echo $product['id']; ?>" class="btn-secondary" style="text-decoration: none; display: inline-block; text-align: center; padding: 5px 10px; font-size: 0.9rem;">Edit</a>
                                    <a href="admin_dashboard.php?tab=products&delete=product&id=<?php echo $product['id']; ?>" class="btn-danger" style="text-decoration: none; display: inline-block; text-align: center; padding: 5px 10px; font-size: 0.9rem;" onclick="return confirm('Are you sure you want to delete this product?')">Delete</a>
                                </div>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            </div>
        </div>
    </div>

    <script>
        // Search functionality
        document.getElementById('product-search')?.addEventListener('input', function(e) {
            const searchTerm = e.target.value.toLowerCase();
            const productCards = document.querySelectorAll('#product-grid .product-card');
            
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

        // Auto-hide notification after 3 seconds
        const notification = document.querySelector('.notification');
        if (notification) {
            setTimeout(() => {
                notification.style.display = 'none';
            }, 3000);
        }
    </script>
</body>
</html>