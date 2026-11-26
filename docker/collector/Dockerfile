ARG GO_VERSION=1.27

FROM golang:${GO_VERSION}-bookworm AS base
WORKDIR /src
COPY collector/go.mod collector/go.sum ./
RUN go mod download
COPY collector/ ./

FROM base AS test
RUN gofmt -l . | (! grep .) && go vet ./... && go test ./...

FROM base AS build
RUN CGO_ENABLED=0 GOOS=linux go build -trimpath -o /out/collector ./cmd/collector

FROM gcr.io/distroless/static-debian12:nonroot
COPY --from=build /out/collector /collector
USER nonroot:nonroot
ENTRYPOINT ["/collector"]
