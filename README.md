# Trading Platform

This is a trading platform's API. The frontend is a Vue 3 application that uses the API to trade.

## Setup
- You need to have Docker and Docker Compose installed. Check here for installation instructions: https://docs.docker.com/get-started/get-docker/
- Copy the .env file from .env.example
    ```shell
    cp .env.example .env
    ```
- Install dependencies

```shell
docker run --rm \
    -u "$(id -u):$(id -g)" \
    -v "$(pwd):/var/www/html" \
    -w /var/www/html \
    laravelsail/php84-composer:latest \
    composer install --ignore-platform-reqs
```

- Boot up application
    ```shell
    vendor/bin/sail up
    ```

- Generate app token
    ```shell
    vendor/bin/sail artisan key:generate
    ```

- Run migrations
    ```shell
    vendor/bin/sail artisan migrate --seed
    ```

- Run tests
    ```shell
    vendor/bin/sail artisan test
    ```

## Running the application

- Run the application
    ```shell
    vendor/bin/sail up
    ```

- Update the pusher credentials in the .env file
    ```shell
    PUSHER_APP_ID=2093100
    PUSHER_APP_KEY=45a87eb2b942a4d2abbb
    PUSHER_APP_SECRET=97a9825a8263355f3760
    PUSHER_APP_CLUSTER=eu
    PUSHER_PORT=443
    PUSHER_SCHEME=https
    ```

The frontend is a Vue 3 application available here https://docs.docker.com/get-started/get-docker/, check its README for setup instructions.
The API will be available at http://localhost:8975 and the frontend will be available at http://localhost:5173 unless the port is not available and vite assigned the frontend app a new one, in that case remember to update the variables `FRONTEND_URL` and `SANCTUM_STATEFUL_DOMAINS` in the .env file of this api app accordingly.