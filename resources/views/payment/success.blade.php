<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Payment Successful</title>
    <style>
        body {
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
            background-color: #f5f7fa;
            margin: 0;
            padding: 0;
            display: flex;
            justify-content: center;
            align-items: center;
            min-height: 100vh;
            color: #333;
        }

        .container {
            background-color: white;
            border-radius: 12px;
            box-shadow: 0 4px 20px rgba(0, 0, 0, 0.1);
            padding: 40px;
            max-width: 500px;
            width: 90%;
            text-align: center;
        }

        .success-icon {
            color: #4CAF50;
            font-size: 80px;
            margin-bottom: 20px;
        }

        h1 {
            color: #2c3e50;
            margin-bottom: 10px;
        }

        p {
            color: #7f8c8d;
            margin-bottom: 30px;
            line-height: 1.6;
        }

        .reference {
            background-color: #f8f9fa;
            padding: 10px 15px;
            border-radius: 6px;
            font-family: monospace;
            margin-bottom: 30px;
            display: inline-block;
        }

        .btn {
            display: inline-block;
            background-color: #3498db;
            color: white;
            padding: 12px 24px;
            border-radius: 6px;
            text-decoration: none;
            font-weight: 600;
            transition: background-color 0.3s;
        }

        .btn:hover {
            background-color: #2980b9;
        }

        .logo {
            margin-bottom: 30px;
            max-width: 150px;
        }
    </style>
</head>

<body>
    <div class="container">
        <div class="success-icon">✓</div>
        <h1>Payment Successful!</h1>
        <p>Your payment has been processed successfully. Thank you for your subscription.</p>
        <div class="reference">Reference: {{ $reference }}</div>
        <a href="sacredionapp://payment/success?reference={{ $reference }}" class="btn">Return to App</a>
    </div>

    <script>
        // Fallback for iOS devices
        document.addEventListener('DOMContentLoaded', function() {
            const returnButton = document.querySelector('.btn');

            returnButton.addEventListener('click', function(e) {
                e.preventDefault();

                // Try to open the app
                window.location.href = this.href;

                // If the app doesn't open after a short delay, show a message
                setTimeout(function() {
                    alert('If you are not redirected to the app, please open it manually.');
                }, 1000);
            });
        });
    </script>
</body>

</html>
