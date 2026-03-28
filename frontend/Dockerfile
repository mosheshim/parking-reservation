FROM node:20-alpine

WORKDIR /app

COPY . .

RUN chmod +x /app/docker-start.sh

EXPOSE 5173

CMD ["sh", "/app/docker-start.sh"]
