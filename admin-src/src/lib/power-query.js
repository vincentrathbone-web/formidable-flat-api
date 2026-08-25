function mString(value) {
  return String(value ?? '').replace(/"/g, '""');
}

/**
 * Build a complete Power Query expression for one Flat API saved-query URL.
 *
 * Keeping the origin in Web.Contents' first argument and the REST path in
 * RelativePath gives Power BI a stable data-source identity for refreshes.
 */
export function buildPowerQuery(endpoint, apiKey) {
  const fallbackOrigin = typeof window !== 'undefined'
    ? window.location.origin
    : 'https://example.invalid';
  const parsed = new URL(endpoint, fallbackOrigin);
  const baseUrl = parsed.origin;
  const relativePath = parsed.pathname.replace(/^\/+/, '') + parsed.search;

  return `let
    BaseUrl = "${mString(baseUrl)}",
    RelativePath = "${mString(relativePath)}",
    Response = Json.Document(
        Web.Contents(
            BaseUrl,
            [
                RelativePath = RelativePath,
                Headers = [
                    #"X-Api-Key" = "${mString(apiKey || '<your-api-key>')}",
                    Accept = "application/json"
                ],
                Timeout = #duration(0, 0, 2, 0)
            ]
        )
    ),
    Result =
        if Value.Is(Response, type list) then
            if List.IsEmpty(Response) then
                #table({}, {})
            else
                Table.FromRecords(Response)
        else
            error "The Flat API returned an unexpected response."
in
    Result`;
}
