<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Picker Menu</title>
  <style>
    body {
      font-family: Arial, sans-serif;
      background-color: #f2f3f5;
      display: flex;
      justify-content: center;
      align-items: center;
      height: 100vh;
      margin: 0;
    }
    .menu-container {
      background: #fff;
      padding: 40px 60px;
      border-radius: 12px;
      box-shadow: 0 4px 12px rgba(0,0,0,0.1);
      text-align: center;
    }
    h1 {
      margin-bottom: 30px;
      color: #333;
    }
    .menu-button {
      display: block;
      width: 200px;
      margin: 10px auto;
      padding: 12px;
      font-size: 16px;
      background-color: #007bff;
      color: white;
      border: none;
      border-radius: 8px;
      cursor: pointer;
      transition: background-color 0.3s ease;
      text-decoration: none;
    }
    .menu-button:hover {
      background-color: #0056b3;
    }
  </style>
</head>
<body>
  <div class="menu-container">
    <h1>Picker Menu</h1>
    <a href="place.php" class="menu-button">Place</a>
    <a href="transfert.php" class="menu-button">Transfert</a>
    <a href="take.php" class="menu-button">Take</a>
  </div>
</body>
</html>
