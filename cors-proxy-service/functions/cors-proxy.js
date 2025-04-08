const fetch = require("node-fetch");

exports.handler = async function (event, context) {
    // Enable CORS
    const headers = {
        "Access-Control-Allow-Origin": "*",
        "Access-Control-Allow-Headers": "Content-Type",
        "Access-Control-Allow-Methods": "POST, OPTIONS",
    };

    // Handle preflight requests
    if (event.httpMethod === "OPTIONS") {
        return {
            statusCode: 200,
            headers,
            body: "",
        };
    }

    // Only allow POST requests
    if (event.httpMethod !== "POST") {
        return {
            statusCode: 405,
            headers,
            body: JSON.stringify({ error: "Method not allowed" }),
        };
    }

    try {
        const { target_url, headers: customHeaders = {} } = JSON.parse(
            event.body
        );

        if (!target_url) {
            return {
                statusCode: 400,
                headers,
                body: JSON.stringify({ error: "target_url is required" }),
            };
        }

        console.log("Making request to:", target_url);

        // Make the request to the target URL
        const response = await fetch(target_url, {
            method: "GET",
            headers: {
                "User-Agent":
                    "Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/134.0.0.0 Safari/537.36",
                Accept: "text/html,application/xhtml+xml,application/xml;q=0.9,image/avif,image/webp,*/*;q=0.8",
                Connection: "keep-alive",
                ...customHeaders,
            },
        });

        // Get the response text
        const responseText = await response.text();

        console.log("Response status:", response.status);

        // Return the response
        return {
            statusCode: 200,
            headers,
            body: JSON.stringify({
                success: response.ok,
                status_code: response.status,
                response: responseText,
                headers: Object.fromEntries(response.headers),
            }),
        };
    } catch (error) {
        console.error("Proxy error:", error);
        return {
            statusCode: 500,
            headers,
            body: JSON.stringify({
                success: false,
                error: error.message,
            }),
        };
    }
};
