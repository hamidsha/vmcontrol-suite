FROM golang:1.26-alpine AS build
WORKDIR /src
COPY go.mod ./
RUN apk add --no-cache ca-certificates git
ENV GOPROXY=direct
RUN go mod download
COPY . .
RUN go mod tidy && \
    CGO_ENABLED=0 go test ./... && \
    CGO_ENABLED=0 go build -trimpath -ldflags='-s -w' -o /out/vmcontrol-gateway ./cmd/gateway

FROM alpine:3.23
RUN apk add --no-cache ca-certificates tzdata
RUN addgroup -S -g 10001 vmcontrol && adduser -S -D -H -u 10001 -G vmcontrol vmcontrol
WORKDIR /app
COPY --from=build /out/vmcontrol-gateway /usr/local/bin/vmcontrol-gateway
COPY static ./static
USER 10001:10001
EXPOSE 8080
ENTRYPOINT ["/usr/local/bin/vmcontrol-gateway"]
