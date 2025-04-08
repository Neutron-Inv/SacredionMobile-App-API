const fetch = require("node-fetch");

exports.handler = async function (event, context) {
    // Only allow POST requests
    if (event.httpMethod !== "POST") {
        return {
            statusCode: 405,
            body: JSON.stringify({ error: "Method not allowed" }),
        };
    }

    try {
        const { target_url, headers = {} } = JSON.parse(event.body);

        if (!target_url) {
            return {
                statusCode: 400,
                body: JSON.stringify({ error: "target_url is required" }),
            };
        }

        // Make the request to the target URL
        const response = await fetch(target_url, {
            method: "GET",
            headers: {
                "User-Agent":
                    "Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/134.0.0.0 Safari/537.36",
                Accept: "text/html,application/xhtml+xml,application/xml;q=0.9,image/avif,image/webp,*/*;q=0.8",
                Connection: "keep-alive",
                ...headers,
            },
        });

        // Get the response text
        const responseText = await response.text();

        // Return the response
        return {
            statusCode: 200,
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
            body: JSON.stringify({
                success: false,
                error: error.message,
            }),
        };
    }
};
