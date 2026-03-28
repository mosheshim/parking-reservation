FROM node:20-alpine

WORKDIR /app

COPY package.json package-lock.json ./
RUN if [ -f package-lock.json ]; then npm ci; else npm install; fi

COPY . .

RUN chmod +x /app/docker-start.sh

EXPOSE 5173

CMD ["sh", "/app/docker-start.sh"]
